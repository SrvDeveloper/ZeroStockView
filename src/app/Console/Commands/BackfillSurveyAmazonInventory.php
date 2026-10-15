<?php

namespace App\Console\Commands;

use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use App\Services\SurveyAmazonInventoryClassifier;
use App\Services\SurveyAmazonInventorySyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

#[Signature('survey:amazon-backfill {--apply : 監査で3ファイルと判定できた履歴だけを更新する} {--json : 件数と調査ID別の差分をJSONで出力する}')]
#[Description('既存の3ファイル調査を同日Amazon取得の保存値へ補正します。通常実行はdry-runです')]
class BackfillSurveyAmazonInventory extends Command
{
    public function handle(SurveyAmazonInventoryClassifier $classifier, SurveyAmazonInventorySyncService $sync): int
    {
        if (! Schema::hasColumns('surveys', ['amazon_source', 'includes_amazon_inventory', 'amazon_inventory_import_id'])
            || ! Schema::hasColumns('survey_skus', ['amazon_own_stock', 'amazon_fba_stock'])) {
            $this->error('Amazon在庫統合のマイグレーションが未適用です。先にスキーマを確認してください。');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $summary = [
            'legacy_file' => 0,
            'three_file' => 0,
            'ambiguous' => 0,
            'surveysToChange' => 0,
            'skusToChange' => 0,
            'pending' => 0,
            'partial' => 0,
            'complete' => 0,
        ];
        $rows = [];
        $currentId = null;

        try {
            Survey::query()->orderBy('id')->chunkById(100, function ($surveys) use ($apply, $classifier, $sync, &$summary, &$rows, &$currentId): void {
                foreach ($surveys as $survey) {
                    $currentId = $survey->id;
                    $row = $apply
                        ? DB::transaction(fn (): array => $this->applySurvey($survey->id, $classifier, $sync))
                        : $this->inspect($survey, $classifier);
                    $summary[$row['classification']]++;
                    if ($row['classification'] === 'three_file') {
                        $summary['surveysToChange'] += $row['willChange'] ? 1 : 0;
                        $summary['skusToChange'] += $row['skuChanges'];
                        $summary[$row['expectedStatus']]++;
                    }
                    $rows[] = $row;
                }
            });
        } catch (Throwable $exception) {
            Log::error('Amazon在庫の既存履歴補正に失敗しました。', ['survey_id' => $currentId, 'exception' => $exception]);
            $this->error("調査ID {$currentId} の処理に失敗しました。処理を停止します。storage/logs/laravel.log を確認してください。適用済みの調査は再実行できます。");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'summary' => $summary, 'surveys' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info($apply ? '既存履歴の補正を実行しました。' : 'dry-run: DBは変更していません。適用には --apply を指定してください。');
            $this->table(['旧5ファイル', '3ファイル', '判定不能', '変更対象調査', '変更対象SKU', '取込待ち', '一部未取得', '取込済み'], [array_values($summary)]);
            $this->table(['調査ID', '日付', '判定', '変更予定', 'SKU変更', '適用前ID', '同日最新ID'], array_map(
                fn (array $row): array => [$row['surveyId'], $row['surveyDate'], $row['classification'], $row['willChange'] ? 'あり' : 'なし', $row['skuChanges'], $row['linkedImportId'], $row['latestSuccessfulImportId']],
                array_values(array_filter($rows, fn (array $row): bool => $row['classification'] === 'ambiguous' || $row['willChange'])),
            ));
            $this->comment('SKU別の補正前後の合計・NULL件数と判定不能の理由は --json で取得できます。');
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function applySurvey(int $id, SurveyAmazonInventoryClassifier $classifier, SurveyAmazonInventorySyncService $sync): array
    {
        $survey = Survey::query()->lockForUpdate()->findOrFail($id);
        $plan = $this->inspect($survey, $classifier);
        if ($plan['classification'] !== 'three_file') {
            return $plan;
        }

        if ($survey->amazon_source !== Survey::AMAZON_SOURCE_SP_API) {
            $survey->amazon_source = Survey::AMAZON_SOURCE_SP_API;
            $survey->save();
        }
        $sync->syncSurvey($id);

        $actual = $this->inspect($survey->fresh(), $classifier);
        $plan['appliedImportId'] = $actual['linkedImportId'];
        $plan['after'] = $actual['before'];

        return $plan;
    }

    /** @return array<string, mixed> */
    private function inspect(Survey $survey, SurveyAmazonInventoryClassifier $classifier): array
    {
        $survey->load(['files', 'products.skus']);
        $skus = $survey->products->flatMap(fn ($product) => $product->skus);
        $nonzeroCount = $skus->filter(fn ($sku): bool => $sku->amazon_own_stock > 0 || $sku->amazon_fba_stock > 0)->count();
        [$classification, $reasons] = $classifier->classify(
            $survey->includes_amazon_inventory,
            $survey->amazon_inventory_import_id,
            $survey->files->pluck('file_type')->all(),
            $skus->count(),
            $nonzeroCount,
            $survey->amazon_source,
        );
        $day = $survey->executed_at->setTimezone('Asia/Tokyo')->toDateString();
        $before = $this->totals($skus, null);
        $row = [
            'surveyId' => $survey->id,
            'surveyDate' => $day,
            'classification' => $classification,
            'reasons' => $reasons,
            'amazonSource' => $survey->amazon_source,
            'linkedImportId' => $survey->amazon_inventory_import_id,
            'latestSuccessfulImportId' => null,
            'willChange' => false,
            'skuChanges' => 0,
            'expectedStatus' => null,
            'before' => $before,
            'expected' => $before,
            'protectedFingerprint' => $classification === 'legacy_file' ? $this->legacyFingerprint($survey) : null,
        ];

        if ($classification !== 'three_file') {
            return $row;
        }

        $latest = AmazonInventoryImport::query()->where('status', 'succeeded')->where('snapshot_date', $day)
            ->orderByDesc('basis_at')->orderByDesc('completed_at')->orderByDesc('id')->first();
        if ($latest !== null && ($latest->basis_at === null || $latest->completed_at === null || ! $latest->snapshots()->exists())) {
            throw new RuntimeException("調査日 {$day} の最新成功取込には基準日時・完了日時・SKU別スナップショットが必要です。");
        }
        $snapshots = $latest?->snapshots()->whereIn('sku_code', $skus->pluck('sku_code'))->get()->keyBy('sku_code') ?? collect();
        $row['latestSuccessfulImportId'] = $latest?->id;
        $row['expected'] = $this->totals($skus, $snapshots);
        $row['expectedStatus'] = $latest === null ? 'pending' : ($row['expected']['nullSkuCount'] > 0 ? 'partial' : 'complete');
        $row['skuChanges'] = $skus->filter(function ($sku) use ($snapshots): bool {
            $snapshot = $snapshots->get($sku->sku_code);

            return $sku->amazon_own_stock !== $snapshot?->amazon_own_stock || $sku->amazon_fba_stock !== $snapshot?->amazon_fba_stock;
        })->count();
        $row['willChange'] = $survey->amazon_source !== Survey::AMAZON_SOURCE_SP_API
            || $survey->amazon_inventory_import_id !== $latest?->id
            || $row['skuChanges'] > 0;

        return $row;
    }

    /** @return array{skuCount: int, nullSkuCount: int, ownTotal: int, fbaTotal: int} */
    private function totals(Collection $skus, ?Collection $snapshots): array
    {
        $summary = ['skuCount' => $skus->count(), 'nullSkuCount' => 0, 'ownTotal' => 0, 'fbaTotal' => 0];
        foreach ($skus as $sku) {
            $source = $snapshots === null ? $sku : $snapshots->get($sku->sku_code);
            $own = $source?->amazon_own_stock;
            $fba = $source?->amazon_fba_stock;
            $summary['nullSkuCount'] += $own === null || $fba === null ? 1 : 0;
            $summary['ownTotal'] += $own ?? 0;
            $summary['fbaTotal'] += $fba ?? 0;
        }

        return $summary;
    }

    private function legacyFingerprint(Survey $survey): string
    {
        $data = [
            'files' => $survey->files->sortBy('id')->map(fn ($file): array => [$file->id, $file->file_type, $file->original_name, $file->stored_path])->values()->all(),
            'products' => $survey->products->sortBy('id')->map(fn ($product): array => [
                $product->id,
                $product->memo,
                $product->skus->sortBy('id')->map(fn ($sku): array => [
                    $sku->id, $sku->sku_code, $sku->amazon_own_stock, $sku->amazon_fba_stock, $sku->memo,
                ])->values()->all(),
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
