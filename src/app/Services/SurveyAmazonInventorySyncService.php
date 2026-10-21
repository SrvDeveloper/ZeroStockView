<?php

namespace App\Services;

use App\Models\AmazonInventoryImport;
use App\Models\AmazonInventorySnapshot;
use App\Models\Survey;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SurveyAmazonInventorySyncService
{
    public function __construct(private BulkRowWriter $writer) {}

    /**
     * Apply the latest successful import for the survey's Tokyo calendar day.
     * Historical surveys without an explicit source are handled by the audited backfill.
     *
     * @return int|null The applied import ID, or null when pending or ineligible.
     */
    public function syncSurvey(int $surveyId): ?int
    {
        return DB::transaction(function () use ($surveyId): ?int {
            $survey = Survey::query()->lockForUpdate()->findOrFail($surveyId);
            if (! $survey->usesSpApiAmazonInventory()) {
                return null;
            }

            $import = $this->importFor($survey);
            $snapshots = $this->snapshotsOf($import);
            if ($snapshots === null) {
                // 保存期間を過ぎてスナップショットを消した日は、調査に写してある値をそのまま残す
                return $survey->amazon_inventory_import_id;
            }

            $changes = [];
            foreach ($survey->products()->with('skus')->get() as $product) {
                foreach ($product->skus as $sku) {
                    $values = $this->amazonValues($sku->child_asin, $snapshots->get($sku->sku_code));
                    $sku->fill($values);
                    if ($sku->isDirty(array_keys($values))) {
                        $changes[$sku->getKey()] = $values;
                    }
                }
            }
            $this->writer->updateById('survey_skus', $changes);

            $survey->amazon_inventory_import_id = $import?->id;
            if ($survey->isDirty('amazon_inventory_import_id')) {
                $survey->save();
            }

            return $import?->id;
        });
    }

    /**
     * 調査を保存したあと、反映済みの取込より新しい成功した取込があるときだけ反映し直す。
     * 調査の作成中に Amazon 在庫の取込が終わった場合に備える。
     *
     * @return int|null The applied import ID, or null when pending or ineligible.
     */
    public function syncSurveyIfNewerImport(int $surveyId): ?int
    {
        $survey = Survey::query()->findOrFail($surveyId);
        if (! $survey->usesSpApiAmazonInventory()) {
            return null;
        }
        if ($this->importFor($survey)?->id === $survey->amazon_inventory_import_id) {
            return $survey->amazon_inventory_import_id;
        }

        return $this->syncSurvey($surveyId);
    }

    /** 調査の日（日本時間の暦日）に反映する取込。まだ成功した取込がなければ null */
    public function importFor(Survey $survey): ?AmazonInventoryImport
    {
        $import = $this->latestSuccessfulImport($survey->executed_at->setTimezone('Asia/Tokyo')->toDateString());
        if ($import !== null && ($import->basis_at === null || $import->completed_at === null)) {
            throw new RuntimeException('成功したAmazon在庫取込の基準日時または完了日時がありません。');
        }

        return $import;
    }

    /**
     * @return Collection<string, AmazonInventorySnapshot>|null SKUコード => スナップショット。
     *                                                          保存期間を過ぎてスナップショットを消した取込は null
     */
    public function snapshotsOf(?AmazonInventoryImport $import): ?Collection
    {
        $snapshots = $import?->snapshots()->get()->keyBy('sku_code') ?? new Collection;
        if ($import !== null && $snapshots->isEmpty()) {
            if ($import->snapshotsExpired()) {
                return null;
            }

            throw new RuntimeException('成功したAmazon在庫取込にSKU別スナップショットがありません。');
        }

        return $snapshots;
    }

    /**
     * 調査のSKU 1 行に書く Amazon の値。スナップショットがなければ在庫は null（未取得／対象外）。
     * 品番マスタに ASIN がないSKUは、スナップショットの ASIN で補う。
     *
     * @return array{child_asin: string|null, amazon_own_stock: int|null, amazon_fba_stock: int|null}
     */
    public function amazonValues(?string $childAsin, ?AmazonInventorySnapshot $snapshot): array
    {
        $hasAsin = is_string($childAsin) && trim($childAsin) !== '';

        return [
            'child_asin' => $snapshot !== null && ! $hasAsin && trim($snapshot->asin) !== '' ? $snapshot->asin : $childAsin,
            'amazon_own_stock' => $snapshot?->amazon_own_stock,
            'amazon_fba_stock' => $snapshot?->amazon_fba_stock,
        ];
    }

    /** @return int Number of three-file surveys synchronized. */
    public function syncDate(CarbonInterface|string $date): int
    {
        $day = $this->tokyoDay($date);
        $count = 0;

        $this->surveysOn($day)
            ->orderBy('id')
            ->chunkById(100, function ($surveys) use (&$count): void {
                foreach ($surveys as $survey) {
                    $this->syncSurvey($survey->id);
                    $count++;
                }
            });

        return $count;
    }

    /** @return int Number of three-file surveys newly synchronized. */
    public function syncPendingDate(CarbonInterface|string $date): int
    {
        $day = $this->tokyoDay($date);
        $latest = $this->latestSuccessfulImport($day->toDateString());
        if ($latest === null) {
            return 0;
        }

        $count = 0;
        $this->surveysOn($day)
            ->where(fn ($query) => $query->whereNull('amazon_inventory_import_id')
                ->orWhere('amazon_inventory_import_id', '!=', $latest->id))
            ->orderBy('id')
            ->chunkById(100, function ($surveys) use (&$count): void {
                foreach ($surveys as $survey) {
                    $this->syncSurvey($survey->id);
                    $count++;
                }
            });

        return $count;
    }

    private function tokyoDay(CarbonInterface|string $date): CarbonImmutable
    {
        return (is_string($date)
            ? CarbonImmutable::parse($date, 'Asia/Tokyo')
            : CarbonImmutable::instance($date)->setTimezone('Asia/Tokyo'))->startOfDay();
    }

    private function surveysOn(CarbonImmutable $day): Builder
    {
        return Survey::query()
            ->where('amazon_source', Survey::AMAZON_SOURCE_SP_API)
            ->where('includes_amazon_inventory', false)
            ->where('executed_at', '>=', $day)
            ->where('executed_at', '<', $day->addDay());
    }

    /** その日（日本時間の暦日 YYYY-MM-DD）の最新の成功した取込 */
    public function latestSuccessfulImport(string $day): ?AmazonInventoryImport
    {
        return AmazonInventoryImport::query()
            ->where('status', 'succeeded')
            ->where('snapshot_date', $day)
            ->orderByDesc('basis_at')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }
}
