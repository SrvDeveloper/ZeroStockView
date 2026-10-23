<?php

namespace App\Services;

use App\Models\BossInventoryHistory as BossInventoryHistoryRecord;
use App\Models\Product;
use App\Models\Survey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * BOSS の在庫変動履歴で、BOSS のレポートを取り込んでいない日の BOSS 在庫を補完する（docs/BOSS在庫の休日補完 BHR-04）。
 * 確認（preview）は計算だけを行い、適用（apply）は保存する時点の調査でもう一度計算してから保存する。
 */
class BossInventoryHistoryImportService
{
    private const TIMEZONE = 'Asia/Tokyo';

    /** 確認の内容に載せる、合わない SKU・倉庫の件数の上限 */
    private const ISSUE_LIMIT = 100;

    /** NULL になる理由の、応答でのキー */
    private const REASON_KEYS = [
        BossInventoryReconstruction::REASON_MISMATCH => 'mismatch',
        BossInventoryReconstruction::REASON_BREAK => 'chainBreak',
        BossInventoryReconstruction::REASON_NEGATIVE => 'negative',
        BossInventoryReconstruction::REASON_NO_DATA => 'noData',
    ];

    public function __construct(
        private BossInventoryHistoryParser $parser,
        private BossInventoryReconstructor $reconstructor,
        private InventoryImportService $importer,
        private SurveyAmazonInventorySyncService $amazonSync,
        private SurveyHistoryPruner $historyPruner,
        private BulkRowWriter $writer,
    ) {}

    /**
     * どの日をどう補完するかを計算する。データベースには書き込まない。
     *
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file): array
    {
        return $this->plan($this->parse($file), false)['summary'];
    }

    /**
     * 補完して調査に保存する。
     *
     * @return array{surveys: EloquentCollection<int, Survey>, summary: array<string, mixed>}
     */
    public function apply(UploadedFile $file, ?User $user): array
    {
        $history = $this->parse($file);
        $storedPath = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                [$surveyIds, $createdIds, $summary] = DB::transaction(function () use ($history, $file, $user, &$storedPath): array {
                    // 取込（import()）と同じく調査の行をロックしてから、保存する時点の内容で計算し直す
                    $plan = $this->plan($history, true);

                    $storedPath = $file->storeAs('boss-inventory-histories', Str::uuid().'.'.(strtolower($file->getClientOriginalExtension()) ?: 'csv'), 'local');
                    if ($storedPath === false) {
                        throw new RuntimeException('在庫変動履歴のファイルを保存できませんでした。');
                    }
                    $record = BossInventoryHistoryRecord::query()->create([
                        'original_name' => $file->getClientOriginalName(),
                        'disk' => 'local',
                        'stored_path' => $storedPath,
                        'mime_type' => $file->getClientMimeType(),
                        'size_bytes' => $file->getSize(),
                        'period_from' => $history->from,
                        'period_to' => $history->to,
                        'row_count' => $history->rowCount,
                        'summary' => $plan['summary'],
                        'uploaded_by' => $user?->getKey(),
                        'uploaded_at' => now(),
                        'expires_at' => now()->addDays(30),
                    ]);

                    $surveyIds = [];
                    $createdIds = [];
                    foreach ($plan['fillDates'] as $date) {
                        $basisAt = $this->basisAt($date);
                        $attributes = [
                            'boss_source' => Survey::BOSS_SOURCE_HISTORY,
                            'boss_basis_at' => $basisAt,
                            'boss_inventory_history_id' => $record->getKey(),
                        ];
                        $values = $plan['reconstruction']->values[$date];
                        $survey = $plan['surveys'][$date];
                        if ($survey === null) {
                            $survey = $this->importer->createWithBossStocks($date, $basisAt, $values, $attributes);
                            $createdIds[] = $survey->getKey();
                        } else {
                            $this->writeBossStocks($plan['skuRows'][$survey->getKey()] ?? [], $values);
                            $survey->forceFill($attributes)->save();
                        }
                        $surveyIds[] = $survey->getKey();
                    }

                    return [$surveyIds, $createdIds, $plan['summary']];
                });
                break;
            } catch (\Throwable $exception) {
                if ($storedPath !== null) {
                    Storage::disk('local')->delete($storedPath);
                    $storedPath = null;
                }
                if ($exception instanceof UniqueConstraintViolationException && $attempt === 0 && str_contains($exception->getMessage(), 'survey_date')) {
                    // 同じ日の調査を取込が先に作った。その調査を読み直して補完する
                    continue;
                }

                throw $exception;
            }
        }

        if ($createdIds !== []) {
            try {
                $this->historyPruner->prune();
            } catch (\Throwable $exception) {
                Log::error('上限を超えた古い調査履歴の削除に失敗しました。', ['reason' => $exception->getMessage()]);
            }
        }
        foreach ($createdIds as $surveyId) {
            try {
                // 調査を作っている間に Amazon 在庫の取込が終わった場合に備える
                $this->amazonSync->syncSurveyIfNewerImport($surveyId);
            } catch (\Throwable $exception) {
                Log::error('BOSS在庫を補完した調査のAmazon在庫再同期に失敗しました。', ['survey_id' => $surveyId, 'reason' => $exception->getMessage()]);
            }
        }

        return [
            'surveys' => Survey::query()->whereKey($surveyIds)->with(['files', 'amazonImport'])->orderBy('executed_at')->orderBy('id')->get(),
            'summary' => $summary,
        ];
    }

    /** 読込の検証メッセージは、アップロードの項目（file）に出す */
    private function parse(UploadedFile $file): BossInventoryHistory
    {
        try {
            if (! $this->parser->isInventoryHistory($file)) {
                throw ValidationException::withMessages(['file' => ['BOSSの在庫変動履歴のファイルではありません。BOSSから出力した在庫変動履歴のCSVを選択してください。']]);
            }

            return $this->parser->parse($file);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['file' => Arr::flatten($exception->errors())]);
        }
    }

    /**
     * 補完する日・基準の調査を決めて再現し、確認の内容を作る（README の前提 2・3）。
     *
     * @param  bool  $lock  期間内の調査の行を、日付の順にロックする（適用のとき）
     * @return array{summary: array<string, mixed>, fillDates: list<string>, surveys: array<string, Survey|null>, skuRows: array<int, list<object>>, reconstruction: BossInventoryReconstruction}
     */
    private function plan(BossInventoryHistory $history, bool $lock): array
    {
        if ($history->from === null || $history->to === null) {
            throw ValidationException::withMessages(['file' => ['在庫変動履歴に、自社倉庫・RFC倉庫の行がありません。']]);
        }

        $dates = [];
        for ($day = $history->from->startOfDay(); $day->lte($history->to); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        /** @var array<string, Survey|null> $surveys */
        $surveys = [];
        foreach ($dates as $date) {
            $surveys[$date] = $this->surveyOn($date, $lock);
        }

        $baseSurveys = array_filter($surveys, fn (?Survey $survey): bool => $survey?->boss_source === Survey::BOSS_SOURCE_REPORT);
        if ($baseSurveys === []) {
            throw ValidationException::withMessages(['file' => ['在庫変動履歴の期間に、BOSSのレポートを取り込んだ日が含まれていません。']]);
        }
        $candidates = [];
        $unfillable = [];
        foreach ($surveys as $date => $survey) {
            if ($survey === null || (! isset($baseSurveys[$date]) && $survey->usesSpApiAmazonInventory())) {
                // 調査がない日と、BOSS のレポートを取り込んでいない日（前回の補完のやり直しを含む）
                $candidates[] = $date;
            } elseif (! isset($baseSurveys[$date])) {
                $unfillable[$date] = '旧方式の調査（Amazon在庫をファイルで取り込んだ調査）のため、補完しません。';
            }
        }

        $fillSurveyIds = array_values(array_filter(array_map(fn (string $date): ?int => $surveys[$date]?->getKey(), $candidates)));
        $skuRows = DB::table('survey_skus AS k')
            ->join('survey_products AS p', 'p.id', '=', 'k.survey_product_id')
            ->whereIn('p.survey_id', [...$fillSurveyIds, ...array_map(fn (Survey $survey): int => $survey->getKey(), $baseSurveys)])
            ->orderBy('k.id')
            ->get(['k.id', 'k.sku_code', 'k.boss_own_stock', 'k.boss_rfc_stock', 'p.survey_id'])
            ->groupBy('survey_id')
            ->map(fn ($rows): array => $rows->all())
            ->all();

        $baseStocks = [];
        foreach ($baseSurveys as $date => $survey) {
            foreach ($skuRows[$survey->getKey()] ?? [] as $row) {
                $baseStocks[$date][$row->sku_code] = ['own' => $this->nullableInt($row->boss_own_stock), 'rfc' => $this->nullableInt($row->boss_rfc_stock)];
            }
            $baseStocks[$date] ??= [];
        }
        // 新しく作る調査の SKU（調査の対象の品番）と、補完する既存の調査の SKU
        $skuCodes = Product::surveyTargets()->pluck('skus')->flatten()->pluck('sku_code')->all();
        foreach ($fillSurveyIds as $surveyId) {
            foreach ($skuRows[$surveyId] ?? [] as $row) {
                $skuCodes[] = $row->sku_code;
            }
        }
        $skuCodes = array_values(array_unique(array_map('strval', $skuCodes)));

        $reconstruction = $this->reconstructor->reconstruct($history, $skuCodes, $baseStocks, $candidates);
        if ($reconstruction->failure !== null) {
            throw ValidationException::withMessages(['file' => ['在庫変動履歴が、BOSSのレポートを出力した時刻より後から始まっています。レポートを出力した日の0:00から出力してください。']]);
        }
        $unfillable += $reconstruction->unavailableDates;
        ksort($unfillable, SORT_STRING);
        $fillDates = array_values(array_filter($candidates, fn (string $date): bool => ! isset($reconstruction->unavailableDates[$date])));
        if ($fillDates === []) {
            throw ValidationException::withMessages(['file' => ['補完する日がありません。']]);
        }

        return [
            'summary' => $this->summary($history, $reconstruction, $baseSurveys, $surveys, $fillDates, $unfillable),
            'fillDates' => $fillDates,
            'surveys' => $surveys,
            'skuRows' => $skuRows,
            'reconstruction' => $reconstruction,
        ];
    }

    /**
     * 確認の応答（BHR-04 の「確認の応答」）。取込記録の summary にも同じ内容を入れる。
     *
     * @param  array<string, Survey>  $baseSurveys
     * @param  array<string, Survey|null>  $surveys
     * @param  list<string>  $fillDates
     * @param  array<string, string>  $unfillable
     * @return array<string, mixed>
     */
    private function summary(BossInventoryHistory $history, BossInventoryReconstruction $reconstruction, array $baseSurveys, array $surveys, array $fillDates, array $unfillable): array
    {
        $warehouseNames = array_flip(BossInventoryHistoryParser::WAREHOUSES);
        $issues = [
            ...array_map(fn (array $mismatch): array => [...$mismatch, 'reason' => BossInventoryReconstruction::REASON_MISMATCH], $reconstruction->mismatches),
            ...$reconstruction->emptiedCells,
        ];

        return [
            'periodFrom' => $history->from->toIso8601String(),
            'periodTo' => $history->to->toIso8601String(),
            'rowCount' => $history->rowCount,
            'typeCounts' => $history->typeCounts,
            'baseSurveys' => array_values(array_map(fn (string $date): array => [
                'date' => $date,
                'surveyId' => (string) $baseSurveys[$date]->getKey(),
                'estimatedReportAt' => $reconstruction->reportTimes[$date]?->toIso8601String(),
                'mismatchCount' => $reconstruction->mismatchCounts[$date] ?? 0,
                'unusableReason' => $reconstruction->unusableBaseDates[$date] ?? null,
            ], array_keys($baseSurveys))),
            'days' => array_map(function (string $date) use ($reconstruction, $surveys): array {
                $summary = $reconstruction->dateSummaries[$date];
                $reasons = [];
                foreach ($summary['reasons'] as $reason => $count) {
                    $reasons[self::REASON_KEYS[$reason]] = $count;
                }

                return [
                    'date' => $date,
                    'basisAt' => $this->basisAt($date)->toIso8601String(),
                    'surveyExists' => $surveys[$date] !== null,
                    'surveyId' => $surveys[$date] === null ? null : (string) $surveys[$date]->getKey(),
                    'filledSkuCount' => $summary['filled'],
                    'nullSkuCount' => $summary['empty'],
                    'nullReasons' => $reasons,
                ];
            }, $fillDates),
            'unfillableDates' => array_map(fn (string $date): array => ['date' => $date, 'reason' => $unfillable[$date]], array_keys($unfillable)),
            'mismatches' => array_map(fn (array $issue): array => [
                'skuCode' => $issue['skuCode'],
                'warehouse' => $warehouseNames[$issue['warehouse']],
                'reason' => self::REASON_KEYS[$issue['reason']],
            ], array_slice($issues, 0, self::ISSUE_LIMIT)),
            'mismatchCount' => $reconstruction->mismatchCount + count($reconstruction->emptiedCells),
        ];
    }

    /** その日の調査。取込（import()）と同じく、調査日で探し、なければ実行日時がその日の最新の調査 */
    private function surveyOn(string $date, bool $lock): ?Survey
    {
        return Survey::query()->where('survey_date', $date)->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? Survey::query()->whereDate('executed_at', $date)
                ->orderByDesc('executed_at')->orderByDesc('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    /**
     * 既存の調査の SKU の BOSS 在庫を、再現した値にする。値の変わる行だけを書く。
     *
     * @param  list<object>  $rows  調査の SKU（id・sku_code・boss_own_stock・boss_rfc_stock）
     * @param  array<string, array{own: int|null, rfc: int|null}>  $values
     */
    private function writeBossStocks(array $rows, array $values): void
    {
        $changes = [];
        foreach ($rows as $row) {
            $own = $values[$row->sku_code]['own'] ?? null;
            $rfc = $values[$row->sku_code]['rfc'] ?? null;
            if ($own !== $this->nullableInt($row->boss_own_stock) || $rfc !== $this->nullableInt($row->boss_rfc_stock)) {
                $changes[$row->id] = ['boss_own_stock' => $own, 'boss_rfc_stock' => $rfc];
            }
        }
        $this->writer->updateById('survey_skus', $changes);
    }

    private function basisAt(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} ".BossInventoryReconstructor::BASIS_TIME, self::TIMEZONE);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
