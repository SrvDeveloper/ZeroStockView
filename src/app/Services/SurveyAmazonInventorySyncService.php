<?php

namespace App\Services;

use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SurveyAmazonInventorySyncService
{
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
            if ($survey->amazon_source !== Survey::AMAZON_SOURCE_SP_API || $survey->includes_amazon_inventory) {
                return null;
            }

            $day = $survey->executed_at->setTimezone('Asia/Tokyo')->toDateString();
            $import = $this->latestSuccessfulImport($day);
            if ($import !== null && ($import->basis_at === null || $import->completed_at === null)) {
                throw new RuntimeException('成功したAmazon在庫取込の基準日時または完了日時がありません。');
            }
            $snapshots = $import?->snapshots()->get()->keyBy('sku_code') ?? collect();
            if ($import !== null && $snapshots->isEmpty()) {
                throw new RuntimeException('成功したAmazon在庫取込にSKU別スナップショットがありません。');
            }

            foreach ($survey->products()->with('skus')->get() as $product) {
                foreach ($product->skus as $sku) {
                    $snapshot = $snapshots->get($sku->sku_code);
                    if ($snapshot !== null && ! $sku->hasAmazonAsin() && trim($snapshot->asin) !== '') {
                        $sku->child_asin = $snapshot->asin;
                    }
                    $sku->amazon_own_stock = $snapshot?->amazon_own_stock;
                    $sku->amazon_fba_stock = $snapshot?->amazon_fba_stock;
                    if ($sku->isDirty(['child_asin', 'amazon_own_stock', 'amazon_fba_stock'])) {
                        $sku->save();
                    }
                }
            }

            $survey->amazon_inventory_import_id = $import?->id;
            if ($survey->isDirty('amazon_inventory_import_id')) {
                $survey->save();
            }

            return $import?->id;
        });
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

    private function latestSuccessfulImport(string $day): ?AmazonInventoryImport
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
