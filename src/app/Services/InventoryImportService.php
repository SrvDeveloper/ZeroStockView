<?php

namespace App\Services;

use App\Models\AmazonInventoryImport;
use App\Models\AmazonInventorySnapshot;
use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InventoryImportService
{
    /** 調査のSKUへ品番マスタから写す列（SKUコードを除く） */
    private const SKU_COLUMNS = ['child_asin', 'status', 'tq_item_no', 'tq_color_no', 'tq_size', 'sort_order'];

    private const STOCK_COLUMNS = ['amazon_own_stock', 'amazon_fba_stock', 'boss_own_stock', 'boss_rfc_stock', 'free_stock', 'ec_stock'];

    private const FILE_TYPES = [
        'boss' => '倉庫毎の在庫数レポート',
        'ec_stock' => 'KEEP一覧表',
        'free_stock' => '在庫一覧照会表',
    ];

    public function __construct(
        private CrossWalkerClient $crossWalker,
        private SurveyAmazonInventorySyncService $amazonSync,
        private BulkRowWriter $writer,
        private SurveyHistoryPruner $historyPruner,
        private CsvFileReader $csv,
    ) {}

    /** @param  ImportSetting|null  $setting  再照合後に表示する品番グループ。指定しなければ、すべての品番グループを表示する */
    public function reimport(Survey $source, ?ImportSetting $setting = null): Survey
    {
        if (! $source->usesSpApiAmazonInventory()) {
            throw ValidationException::withMessages([
                'survey' => ['Amazon在庫をファイルで取り込んだ旧方式の調査のため、再照合できません。'],
            ]);
        }

        $source->loadMissing('files');
        $files = [];

        $sourceFiles = $source->files->whereIn('file_type', array_values(self::FILE_TYPES));
        if ($sourceFiles->isEmpty()) {
            throw ValidationException::withMessages(['files' => ['再照合に使える取込ファイルがないため、再照合できません。']]);
        }

        foreach ($sourceFiles as $file) {
            if ($file->expires_at === null || $file->expires_at->isPast() || ! Storage::disk($file->disk)->exists($file->stored_path)) {
                throw ValidationException::withMessages([
                    'files' => ['取込ファイルが削除されているため、再照合できません。'],
                ]);
            }

            $files[] = new UploadedFile(
                Storage::disk($file->disk)->path($file->stored_path),
                $file->original_name,
                $file->mime_type,
                null,
                true,
            );
        }

        $survey = $this->import($files, $setting, $source->executed_at, $source);
        if ($survey->boss_source === Survey::BOSS_SOURCE_HISTORY) {
            // 補完の処理はこのサービスを使うので、コンストラクタでは受け取らずに呼ぶときに解決する
            app(BossInventoryHistoryImportService::class)->reapply($survey);
            $survey->load(['products.skus', 'files', 'amazonImport']);
        }

        return $survey;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  CarbonInterface|null  $executedAt  在庫データの基準日時。新規取込では現在日時、再照合では元の調査日時を使用する。
     */
    public function import(array $files, ?ImportSetting $setting = null, ?CarbonInterface $executedAt = null, ?Survey $source = null): Survey
    {
        $basisAt = $executedAt ?? now();
        $classifiedFiles = $this->classifyFiles($files);

        // CrossWalker で有効な全品番を、在庫調査の対象にする
        $this->crossWalker->refreshActiveProducts();
        [$targets, $groupMemberships] = $this->loadTargets();
        if ($targets->isEmpty()) {
            throw ValidationException::withMessages([
                'targets' => ['CrossWalkerに有効な品番がありません。'],
            ]);
        }

        $storedFiles = [];
        $replacedFiles = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $survey = DB::transaction(function () use ($targets, $groupMemberships, $classifiedFiles, $setting, $basisAt, $source, &$storedFiles, &$replacedFiles): Survey {
                    $date = $basisAt->toDateString();
                    $survey = Survey::query()->where('survey_date', $date)->lockForUpdate()->first();
                    if ($survey === null && $source !== null) {
                        $survey = Survey::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
                    }
                    if ($survey === null) {
                        $survey = Survey::query()
                            ->whereDate('executed_at', $date)
                            ->orderByDesc('executed_at')->orderByDesc('id')
                            ->lockForUpdate()->first();
                    }
                    $previousSettingName = $survey?->import_setting_name;
                    if ($survey === null) {
                        $survey = Survey::query()->create([
                            'executed_at' => $basisAt,
                            'survey_date' => $date,
                            'import_setting_name' => $setting?->name,
                            'includes_amazon_inventory' => false,
                            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
                            'amazon_inventory_import_id' => null,
                        ]);
                    } else {
                        $survey->update([
                            'survey_date' => $date,
                            'import_setting_name' => $setting?->name,
                            'includes_amazon_inventory' => false,
                            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
                        ]);
                        $survey->touch();
                    }

                    $existingFiles = $survey->files()->get()->keyBy('file_type');
                    $allFiles = $classifiedFiles;
                    foreach (self::FILE_TYPES as $key => $type) {
                        if (isset($allFiles[$key])) {
                            continue;
                        }
                        $saved = $existingFiles->get($type);
                        if ($saved !== null && $saved->expires_at?->isFuture() && Storage::disk($saved->disk)->exists($saved->stored_path)) {
                            $allFiles[$key] = new UploadedFile(
                                Storage::disk($saved->disk)->path($saved->stored_path),
                                $saved->original_name,
                                $saved->mime_type,
                                null,
                                true,
                            );
                        }
                    }

                    $skus = $targets->pluck('skus')->flatten();
                    // 0 は在庫数が0と確認できたことだけを表す。BOSSは全SKUを載せるので、載っていないSKUは在庫が分からず null のままにする。
                    // KEEP一覧表・フリー在庫（ゼロ在庫を出力しない）は、載っていなければ0
                    $stocks = $skus->mapWithKeys(fn ($sku): array => [
                        $sku->sku_code => [
                            'boss_own_stock' => null,
                            'boss_rfc_stock' => null,
                            'free_stock' => isset($allFiles['free_stock']) ? 0 : null,
                            'ec_stock' => isset($allFiles['ec_stock']) ? 0 : null,
                        ],
                    ])->all();
                    $skuLookup = $skus->pluck('sku_code', 'sku_code')->all();
                    $tqLookup = $skus->mapWithKeys(fn ($sku): array => [
                        $this->tqKey($sku->tq_item_no, $sku->tq_color_no, $sku->tq_size) => $sku->sku_code,
                    ])->all();
                    if (isset($allFiles['boss'])) {
                        $this->applyBoss($stocks, $skuLookup, $allFiles['boss']);
                        // BOSS のレポートの値にする（在庫変動履歴から再現した日でも、レポートで上書きする）
                        $survey->forceFill(['boss_source' => Survey::BOSS_SOURCE_REPORT, 'boss_basis_at' => null, 'boss_inventory_history_id' => null])->save();
                    } elseif ($survey->boss_source === Survey::BOSS_SOURCE_HISTORY) {
                        $this->keepReconstructedBoss($stocks, $survey);
                    }
                    if (isset($allFiles['ec_stock'])) {
                        $this->applyTq($stocks, $tqLookup, $allFiles['ec_stock'], 'キープ残数', 'ec_stock', true);
                    }
                    if (isset($allFiles['free_stock'])) {
                        $this->applyTq($stocks, $tqLookup, $allFiles['free_stock'], '可能在庫数', 'free_stock', false);
                    }

                    // その日の Amazon 在庫があれば、行を書くときに一緒に入れる
                    $amazonImport = $this->amazonSync->importFor($survey);
                    $snapshots = $this->amazonSync->snapshotsOf($amazonImport);
                    $this->writeProducts($survey, $targets, $groupMemberships, $stocks, $snapshots, $previousSettingName);
                    if ($snapshots !== null) {
                        $survey->forceFill(['amazon_inventory_import_id' => $amazonImport?->id])->save();
                    }

                    foreach ($classifiedFiles as $key => $file) {
                        $type = self::FILE_TYPES[$key];
                        $oldFile = $existingFiles->get($type);
                        if ($oldFile !== null) {
                            $replacedFiles[] = [$oldFile->disk, $oldFile->stored_path];
                            $oldFile->delete();
                        }
                        $extension = strtolower($file->getClientOriginalExtension()) ?: 'dat';
                        $path = $file->storeAs(
                            'survey-imports/'.$survey->getKey(),
                            Str::uuid().'.'.$extension,
                            'local',
                        );

                        if ($path === false) {
                            throw new RuntimeException('取込ファイルを保存できませんでした。');
                        }

                        $storedFiles[] = $path;
                        // 再照合は元のファイルを使い直すだけなので、アップロード日時と保存期限は元のファイルのままにする
                        $original = $source?->files->firstWhere('file_type', $type);
                        $survey->files()->create([
                            'file_type' => $type,
                            'original_name' => $file->getClientOriginalName(),
                            'disk' => 'local',
                            'stored_path' => $path,
                            'mime_type' => $file->getClientMimeType(),
                            'size_bytes' => $file->getSize(),
                            'uploaded_at' => $original?->uploaded_at ?? now(),
                            'expires_at' => $original?->expires_at ?? now()->addDays(30),
                        ]);
                    }

                    return $survey;
                });
                break;
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($storedFiles);
                if ($exception instanceof UniqueConstraintViolationException && $source === null
                    && $attempt === 0 && str_contains($exception->getMessage(), 'survey_date')) {
                    // Another request claimed this day first. Re-read and update that row.
                    $storedFiles = [];
                    $replacedFiles = [];

                    continue;
                }

                throw $exception;
            }
        }

        foreach ($replacedFiles as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
        if ($survey->wasRecentlyCreated) {
            $this->pruneHistory();
        }

        try {
            // Catch an import that finished while the survey transaction was committing.
            $this->amazonSync->syncSurveyIfNewerImport($survey->id);
        } catch (\Throwable $exception) {
            Log::error('調査保存後のAmazon在庫再同期に失敗しました。', [
                'survey_id' => $survey->id,
                'reason' => $exception->getMessage(),
            ]);
        }

        return $survey->refresh()->load(['products.skus', 'files', 'amazonImport']);
    }

    /**
     * Amazon在庫の取得だけが成功した日に、ファイルなしの調査を作成する。
     * 品番・SKUは手元の商品情報を使い、CrossWalkerは呼ばない（Amazon取得の開始時に最新化済み）。
     * BOSS・フリー在庫・ECストックは取込待ち（null）とし、Amazon数量は同日の最新成功分を反映する。
     *
     * @return Survey|null 作成した調査。成功した取込でない、同じ日の調査がすでにある、対象品番がない場合は null
     */
    public function createFromAmazonImport(AmazonInventoryImport $import): ?Survey
    {
        if ($import->status !== 'succeeded' || $import->snapshot_date === null || $import->basis_at === null) {
            return null;
        }

        $date = $import->snapshot_date->toDateString();
        // 保存期間を過ぎてスナップショットを消した日は、Amazon在庫を写せないので調査を作らない
        if ($this->surveyExistsOn($date) || ($import->snapshotsExpired() && ! $import->snapshots()->exists())) {
            return null;
        }

        [$targets, $groupMemberships] = $this->loadTargets();
        if ($targets->isEmpty()) {
            return null;
        }

        $completedAt = $import->completed_at?->copy()->setTimezone('Asia/Tokyo');
        $executedAt = $completedAt !== null && $completedAt->toDateString() === $date ? $import->completed_at : $import->basis_at;

        try {
            $created = DB::transaction(function () use ($targets, $groupMemberships, $date, $executedAt): ?Survey {
                if ($this->surveyExistsOn($date)) {
                    return null;
                }

                return $this->createWithoutFiles($targets, $groupMemberships, $date, $executedAt, []);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'survey_date')) {
                // 同じ日の調査をファイル取込が先に作成した。Amazon数量はその調査へ同期される。
                return null;
            }

            throw $exception;
        }
        if ($created !== null) {
            $this->pruneHistory();
        }

        return $created;
    }

    /**
     * 在庫変動履歴で BOSS 在庫を補完する日に、調査がなければファイルなしの調査を作る（docs/BOSS在庫の休日補完 BHR-04）。
     * 形は createFromAmazonImport() と同じで、BOSS は再現した値、フリー在庫・ECストックは取込待ち（null）、Amazon 数量は同日の最新成功分。
     * 呼び出し側のトランザクションの中で呼ぶ。調査履歴の上限の処理と、コミット後の Amazon 在庫の再同期は呼び出し側で行う。
     *
     * @param  array<string, array{own: int|null, rfc: int|null}>  $bossStocks  SKUコード => 再現した BOSS 在庫。ない SKU は null
     * @param  array<string, mixed>  $attributes  調査に書く BOSS の取得方式など
     */
    public function createWithBossStocks(string $date, CarbonInterface $executedAt, array $bossStocks, array $attributes): Survey
    {
        [$targets, $groupMemberships] = $this->loadTargets();

        return $this->createWithoutFiles($targets, $groupMemberships, $date, $executedAt, $bossStocks, $attributes);
    }

    /**
     * @param  Collection<int, Product>  $targets
     * @param  Collection<int, list<array{key: string, name: string, sortOrder: int}>>  $groupMemberships
     * @param  array<string, array{own: int|null, rfc: int|null}>  $bossStocks
     * @param  array<string, mixed>  $attributes
     */
    private function createWithoutFiles(Collection $targets, Collection $groupMemberships, string $date, CarbonInterface $executedAt, array $bossStocks, array $attributes = []): Survey
    {
        $survey = Survey::query()->create([
            'executed_at' => $executedAt,
            'survey_date' => $date,
            'import_setting_name' => null,
            'includes_amazon_inventory' => false,
            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
            'amazon_inventory_import_id' => null,
            ...$attributes,
        ]);

        $stocks = $targets->pluck('skus')->flatten()->mapWithKeys(fn ($sku): array => [
            $sku->sku_code => [
                'boss_own_stock' => $bossStocks[$sku->sku_code]['own'] ?? null,
                'boss_rfc_stock' => $bossStocks[$sku->sku_code]['rfc'] ?? null,
                'free_stock' => null,
                'ec_stock' => null,
            ],
        ])->all();
        $amazonImport = $this->amazonSync->importFor($survey);
        $this->writeProducts($survey, $targets, $groupMemberships, $stocks, $this->amazonSync->snapshotsOf($amazonImport), null);
        $survey->forceFill(['amazon_inventory_import_id' => $amazonImport?->id])->save();

        return $survey;
    }

    /**
     * 在庫変動履歴から BOSS 在庫を再現した調査に、BOSS のファイルなしで取り込むときは、再現した値を SKU コードで引き継ぐ
     * （docs/BOSS在庫の休日補完 BHR-05）。新しく入った SKU は null のまま。
     *
     * @param  array<string, array<string, int|null>>  $stocks
     */
    private function keepReconstructedBoss(array &$stocks, Survey $survey): void
    {
        $saved = DB::table('survey_skus AS k')
            ->join('survey_products AS p', 'p.id', '=', 'k.survey_product_id')
            ->where('p.survey_id', $survey->getKey())
            ->get(['k.sku_code', 'k.boss_own_stock', 'k.boss_rfc_stock']);
        foreach ($saved as $row) {
            if (isset($stocks[$row->sku_code])) {
                $stocks[$row->sku_code]['boss_own_stock'] = $row->boss_own_stock === null ? null : (int) $row->boss_own_stock;
                $stocks[$row->sku_code]['boss_rfc_stock'] = $row->boss_rfc_stock === null ? null : (int) $row->boss_rfc_stock;
            }
        }
    }

    /**
     * 調査を新しく作ったあと、上限（500件）を超えた古い調査を削除する。
     * 調査の保存はすでに終わっているので、失敗してもログに残すだけにする（次に調査を作ったときに削除し直す）。
     */
    private function pruneHistory(): void
    {
        try {
            $this->historyPruner->prune();
        } catch (\Throwable $exception) {
            Log::error('上限を超えた古い調査履歴の削除に失敗しました。', ['reason' => $exception->getMessage()]);
        }
    }

    private function surveyExistsOn(string $date): bool
    {
        return Survey::query()
            ->where(fn ($query) => $query->where('survey_date', $date)->orWhereDate('executed_at', $date))
            ->exists();
    }

    /**
     * 在庫調査の対象の品番（CrossWalker で有効な全品番）と、有効なSKU・各品番の品番グループの所属を読み込む。
     * どの品番グループにも入っていない品番は、所属なしになる（画面では「グループなし」）。
     *
     * @return array{0: Collection<int, Product>, 1: Collection<int, list<array{key: string, name: string, sortOrder: int}>>}
     */
    private function loadTargets(): array
    {
        $targets = Product::surveyTargets();
        $groupMemberships = ImportTarget::query()->with('setting')
            ->whereIn('product_id', $targets->modelKeys())
            ->orderBy('import_setting_id')->orderBy('sort_order')->get()
            ->groupBy('product_id')->map(fn ($members): array => $members->map(fn ($member): array => [
                'key' => 'setting:'.$member->import_setting_id,
                'name' => $member->setting->name,
                'sortOrder' => $member->sort_order,
            ])->all());

        return [$targets, $groupMemberships];
    }

    /**
     * 調査の品番・SKUを、品番マスタと取込結果に合わせる。変わった行だけを書き、行のIDとメモは引き継ぐ。
     * 品番は品番コード、SKUは品番コードとSKUコードで突き合わせ、なくなったものは消して新しいものを追加する。
     * 別の品番へ移ったSKUは入れ直し、メモはSKUコードで引き継ぐ。グループ所属は以前の所属と合わせる。
     *
     * @param  Collection<int, Product>  $targets
     * @param  Collection<int, list<array{key: string, name: string, sortOrder: int}>>  $groupMemberships
     * @param  array<string, array<string, int|null>>  $stocks  SKUコード => Amazon 以外の在庫
     * @param  EloquentCollection<string, AmazonInventorySnapshot>|null  $snapshots  SKUコード => その日の Amazon 在庫。
     *                                                                               null は保存期間を過ぎて消した日で、調査にある Amazon の値を残す
     */
    private function writeProducts(Survey $survey, Collection $targets, Collection $groupMemberships, array $stocks, ?EloquentCollection $snapshots, ?string $previousSettingName): void
    {
        $existingProducts = $survey->products()->with('skus')->get()->keyBy('product_code');
        $skuMemos = $existingProducts->flatMap->skus->pluck('memo', 'sku_code');
        $settingKeysByName = $groupMemberships->flatten(1)->pluck('key', 'name')->all();

        $desiredProducts = [];
        foreach ($targets as $index => $product) {
            $priorProduct = $existingProducts->get($product->product_code);
            $priorMemberships = collect($priorProduct?->group_memberships ?? []);
            if ($priorProduct !== null && $priorMemberships->isEmpty() && $previousSettingName !== null) {
                $priorMemberships->push(['key' => 'legacy:'.$previousSettingName, 'name' => $previousSettingName, 'sortOrder' => $priorProduct->sort_order]);
            }
            $desiredProducts[$product->product_code] = [
                ...$this->rawValues($product, ['brand', 'category', 'parent_asin', 'status', 'source_updated_at']),
                'sort_order' => $index + 1,
                // 現在の所属を先に置き、同じグループは現在の名前と並び順を使う
                'group_memberships' => SurveyProduct::mergeGroupMemberships(
                    collect($groupMemberships->get($product->id, []))->concat($priorMemberships),
                    $settingKeysByName,
                ),
            ];
        }

        // なくなった品番の調査SKUは、外部キーで一緒に消える
        $removedProducts = $existingProducts->diffKeys($desiredProducts);
        foreach (array_chunk($removedProducts->modelKeys(), 500) as $chunk) {
            SurveyProduct::query()->whereKey($chunk)->delete();
        }
        $keptProducts = $existingProducts->diffKeys($removedProducts);
        $this->writeRows(
            'survey_products',
            $keptProducts->map(fn (SurveyProduct $product): array => [
                ...$this->rawValues($product, ['id', 'brand', 'category', 'parent_asin', 'status', 'source_updated_at', 'sort_order']),
                'group_memberships' => $product->group_memberships ?? [],
            ])->all(),
            $desiredProducts,
            fn (string $productCode): array => ['survey_id' => $survey->getKey(), 'product_code' => $productCode, 'memo' => null],
        );
        $productIds = SurveyProduct::query()->where('survey_id', $survey->getKey())->pluck('id', 'product_code');

        $existingSkus = [];
        foreach ($keptProducts as $productCode => $product) {
            foreach ($product->skus as $sku) {
                $existingSkus[$productCode."\x1f".$sku->sku_code] = $this->rawValues($sku, ['id', 'survey_product_id', ...self::SKU_COLUMNS, ...self::STOCK_COLUMNS]);
            }
        }
        $desiredSkus = [];
        $skuCodes = [];
        foreach ($targets as $product) {
            $productCode = $product->product_code;
            foreach ($product->skus as $sku) {
                $key = $productCode."\x1f".$sku->sku_code;
                $skuCodes[$key] = $sku->sku_code;
                $desiredSkus[$key] = [
                    'survey_product_id' => $productIds[$productCode],
                    ...$this->rawValues($sku, self::SKU_COLUMNS),
                    ...$stocks[$sku->sku_code],
                    ...($snapshots === null && isset($existingSkus[$key])
                        ? $this->keptAmazonValues($sku->child_asin, $existingSkus[$key])
                        : $this->amazonSync->amazonValues($sku->child_asin, $snapshots?->get($sku->sku_code))),
                ];
            }
        }

        foreach (array_chunk(array_column(array_diff_key($existingSkus, $desiredSkus), 'id'), 500) as $chunk) {
            SurveySku::query()->whereKey($chunk)->delete();
        }
        $this->writeRows(
            'survey_skus',
            array_intersect_key($existingSkus, $desiredSkus),
            $desiredSkus,
            fn (string $key): array => ['sku_code' => $skuCodes[$key], 'memo' => $skuMemos->get($skuCodes[$key])],
        );
    }

    /**
     * スナップショットを消した日に、調査SKUにある Amazon の値をそのまま残す。ASIN は品番マスタにあればそれを使う。
     *
     * @param  array<string, mixed>  $existing  調査SKUの既存の行
     * @return array{child_asin: mixed, amazon_own_stock: mixed, amazon_fba_stock: mixed}
     */
    private function keptAmazonValues(?string $childAsin, array $existing): array
    {
        return [
            'child_asin' => is_string($childAsin) && trim($childAsin) !== '' ? $childAsin : $existing['child_asin'],
            'amazon_own_stock' => $existing['amazon_own_stock'],
            'amazon_fba_stock' => $existing['amazon_fba_stock'],
        ];
    }

    /**
     * 既存の行を望む値に合わせ、足りない行を追加する。値の変わらない行は書かない。
     * 並び順（一意制約あり）が入れ替わる行は、先に重ならない値へ退避してから書く。
     *
     * @param  array<string, array<string, mixed>>  $current  キー => 既存の行（id と比べる列。値は DB に保存されている形）
     * @param  array<string, array<string, mixed>>  $desired  キー => 望む行（sort_order を含む。配列の値は JSON で保存する）
     * @param  callable(string): array<string, mixed>  $newRowValues  追加する行だけに書く値
     */
    private function writeRows(string $table, array $current, array $desired, callable $newRowValues): void
    {
        $encode = fn (array $row): array => array_map(fn (mixed $value): mixed => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value, $row);
        $changes = [];
        $movedIds = [];
        foreach (array_intersect_key($desired, $current) as $key => $row) {
            $existing = $current[$key];
            foreach ($row as $column => $value) {
                $differs = is_array($value)
                    ? $value != $existing[$column]
                    : ($value === null) !== ($existing[$column] === null) || (string) $value !== (string) $existing[$column];
                if ($differs) {
                    $changes[$existing['id']] = $encode($row);
                    break;
                }
            }
            if ((int) $row['sort_order'] !== (int) $existing['sort_order']) {
                $movedIds[] = $existing['id'];
            }
        }

        if ($movedIds !== []) {
            $offset = max([...array_column($current, 'sort_order'), ...array_column($desired, 'sort_order')]) + 1;
            $this->writer->moveSortOrderAside($table, $movedIds, $offset);
        }
        $this->writer->updateById($table, $changes);
        $this->writer->insert($table, array_map(
            fn (string $key): array => $encode($desired[$key] + $newRowValues($key)),
            array_keys(array_diff_key($desired, $current)),
        ));
    }

    /**
     * モデルの列を、DB に保存されている形のまま取り出す。
     *
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function rawValues(Model $model, array $columns): array
    {
        $attributes = $model->getAttributes();

        return array_combine($columns, array_map(fn (string $column): mixed => $attributes[$column] ?? null, $columns));
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<string, UploadedFile>
     */
    private function classifyFiles(array $files): array
    {
        if (count($files) < 1 || count($files) > count(self::FILE_TYPES)) {
            throw ValidationException::withMessages(['files' => ['取込ファイルを1〜3件添付してください。']]);
        }

        $classified = [];
        foreach ($files as $file) {
            $headerRow = $this->csv->headerRow($file);
            $headers = $headerRow === false ? [] : array_map($this->csv->normalize(...), $headerRow);

            $matchingTypes = array_keys(array_filter([
                'boss' => $this->hasHeaders($headers, ['倉庫', 'SKUコード', '実在庫 (倉庫毎)', '引当済 (倉庫毎)']),
                'ec_stock' => $this->hasHeaders($headers, ['品番', 'カラーNo', 'キープ残数']) && $this->hasAnyHeader($headers, ['サイズ', 'ｻｲｽﾞ']),
                'free_stock' => $this->hasHeaders($headers, ['品番', 'カラーNo', '可能在庫数']) && $this->hasAnyHeader($headers, ['サイズ', 'ｻｲｽﾞ']),
            ]));
            if (count($matchingTypes) !== 1) {
                throw ValidationException::withMessages(['files' => [$file->getClientOriginalName().'の種類を内容から判定できません。ヘッダーを確認してください。']]);
            }
            $type = $matchingTypes[0];
            if (isset($classified[$type])) {
                throw ValidationException::withMessages(['files' => [self::FILE_TYPES[$type].'のファイルが複数あります。']]);
            }
            $classified[$type] = $file;
        }

        return $classified;
    }

    /** @param array<int, string> $headers @param array<int, string> $required */
    private function hasHeaders(array $headers, array $required): bool
    {
        return count(array_intersect($required, $headers)) === count($required);
    }

    /** @param array<int, string> $headers @param array<int, string> $candidates */
    private function hasAnyHeader(array $headers, array $candidates): bool
    {
        return count(array_intersect($candidates, $headers)) > 0;
    }

    /**
     * @param  array<string, array<string, int|null>>  $stocks
     * @param  array<string, string>  $skuLookup
     */
    private function applyBoss(array &$stocks, array $skuLookup, UploadedFile $file): void
    {
        $seen = [];
        foreach ($this->csv->rows($file, ['倉庫', 'SKUコード', '実在庫 (倉庫毎)', '引当済 (倉庫毎)']) as $rowNumber => $row) {
            if (! isset($skuLookup[$row['SKUコード']])) {
                continue;
            }
            $skuCode = $skuLookup[$row['SKUコード']];
            // BOSSは登録されている全SKUを載せるので、載っているSKUは、片方の倉庫の行が無くてもその倉庫を0とする
            $stocks[$skuCode]['boss_own_stock'] ??= 0;
            $stocks[$skuCode]['boss_rfc_stock'] ??= 0;
            $stockField = match ($row['倉庫']) {
                '自社倉庫' => 'boss_own_stock',
                'RFC倉庫' => 'boss_rfc_stock',
                default => null,
            };
            if ($stockField === null) {
                continue;
            }

            $physicalStock = $this->quantity($row['実在庫 (倉庫毎)'], $file, $rowNumber);
            $allocatedStock = $this->quantity($row['引当済 (倉庫毎)'], $file, $rowNumber);
            if ($physicalStock === null || $allocatedStock === null) {
                continue;
            }

            $quantity = $physicalStock - $allocatedStock;
            if ($quantity < 0) {
                throw ValidationException::withMessages(['files' => ["{$file->getClientOriginalName()}の{$rowNumber}行目の数量がマイナスです。"]]);
            }

            $this->ensureNotDuplicate($seen, $stockField.':'.$skuCode, $file, $rowNumber);
            $stocks[$skuCode][$stockField] = $quantity;
        }
    }

    /**
     * @param  array<string, array<string, int|null>>  $stocks
     * @param  array<string, string>  $tqLookup
     */
    private function applyTq(array &$stocks, array $tqLookup, UploadedFile $file, string $quantityHeader, string $stockField, bool $sum): void
    {
        $seen = [];
        $rows = $this->csv->rows($file, ['品番', 'カラーNo', ['サイズ', 'ｻｲｽﾞ'], $quantityHeader]);

        foreach ($rows as $rowNumber => $row) {
            $size = $row['サイズ'] ?? $row['ｻｲｽﾞ'] ?? '';
            $key = $this->tqKey($row['品番'], $row['カラーNo'], $size);

            if (! isset($tqLookup[$key])) {
                continue;
            }

            $quantity = $this->quantity($row[$quantityHeader], $file, $rowNumber);
            if ($quantity === null || $quantity === 0) {
                continue;
            }

            $skuCode = $tqLookup[$key];
            if (! $sum) {
                $this->ensureNotDuplicate($seen, $skuCode, $file, $rowNumber);
            }
            $stocks[$skuCode][$stockField] = $sum
                ? $stocks[$skuCode][$stockField] + $quantity
                : $quantity;
        }
    }

    private function quantity(?string $value, UploadedFile $file, int $rowNumber): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^-?\\d+(?:\\.0+)?$/', $value)) {
            throw ValidationException::withMessages(['files' => ["{$file->getClientOriginalName()}の{$rowNumber}行目の数量が数値ではありません。"]]);
        }
        $quantity = (int) $value;
        if ($quantity < 0) {
            throw ValidationException::withMessages(['files' => ["{$file->getClientOriginalName()}の{$rowNumber}行目の数量がマイナスです。"]]);
        }

        return $quantity;
    }

    /** @param array<string, bool> $seen */
    private function ensureNotDuplicate(array &$seen, string $key, UploadedFile $file, int $rowNumber): void
    {
        if (isset($seen[$key])) {
            throw ValidationException::withMessages(['files' => ["{$file->getClientOriginalName()}の{$rowNumber}行目で対象SKUが重複しています。"]]);
        }
        $seen[$key] = true;
    }

    private function tqKey(string $item, string $color, string $size): string
    {
        return implode("\x1f", [$item, $color, $size]);
    }
}
