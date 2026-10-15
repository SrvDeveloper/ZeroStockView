<?php

namespace App\Console\Commands;

use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use App\Services\SurveyAmazonInventoryClassifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('survey:amazon-audit {--json : 全調査の判定結果をJSONで出力する}')]
#[Description('Amazon在庫の統合移行対象を読取り専用で監査します')]
class AuditSurveyAmazonInventory extends Command
{
    public function handle(SurveyAmazonInventoryClassifier $classifier): int
    {
        if (! Schema::hasColumns('surveys', ['includes_amazon_inventory', 'amazon_inventory_import_id'])) {
            $this->error('調査のAmazon取得方式を示すマイグレーションが未適用です。先にスキーマを確認してください。');

            return self::FAILURE;
        }

        $imports = AmazonInventoryImport::query()
            ->get(['id', 'status', 'snapshot_date', 'basis_at', 'completed_at']);
        $importsById = $imports->keyBy('id');
        $successByDay = $imports->where('status', 'succeeded')
            ->filter(fn (AmazonInventoryImport $import): bool => $import->snapshot_date !== null)
            ->groupBy(fn (AmazonInventoryImport $import): string => $import->snapshot_date->toDateString());
        $latestByDay = $successByDay->map(fn ($daily) => $daily
            ->sort(fn (AmazonInventoryImport $a, AmazonInventoryImport $b): int => [$b->basis_at?->getTimestamp() ?? 0, $b->completed_at?->getTimestamp() ?? 0, $b->id]
                <=> [$a->basis_at?->getTimestamp() ?? 0, $a->completed_at?->getTimestamp() ?? 0, $a->id])
            ->first());

        $summary = ['legacy_file' => 0, 'three_file' => 0, 'ambiguous' => 0];
        $rows = [];

        Survey::query()->orderBy('id')->chunkById(100, function ($surveys) use (&$summary, &$rows, $importsById, $latestByDay, $classifier): void {
            foreach ($surveys as $survey) {
                $files = $survey->files()->pluck('file_type')->all();
                $stocks = DB::table('survey_skus as sku')
                    ->join('survey_products as product', 'product.id', '=', 'sku.survey_product_id')
                    ->where('product.survey_id', $survey->id)
                    ->selectRaw('COUNT(*) as sku_count, SUM(CASE WHEN sku.amazon_own_stock > 0 OR sku.amazon_fba_stock > 0 THEN 1 ELSE 0 END) as nonzero_sku_count, SUM(sku.amazon_own_stock) as own_total, SUM(sku.amazon_fba_stock) as fba_total')
                    ->first();
                $skuCount = (int) $stocks->sku_count;
                $nonzeroCount = (int) $stocks->nonzero_sku_count;
                [$classification, $reasons] = $classifier->classify(
                    $survey->includes_amazon_inventory,
                    $survey->amazon_inventory_import_id,
                    $files,
                    $skuCount,
                    $nonzeroCount,
                );

                $day = $survey->executed_at->setTimezone('Asia/Tokyo')->toDateString();
                $linked = $survey->amazon_inventory_import_id === null ? null : $importsById->get($survey->amazon_inventory_import_id);
                $linkedDay = $linked?->snapshot_date?->toDateString();
                if ($linked !== null && ($linked->status !== 'succeeded' || $linkedDay !== $day)) {
                    $reasons[] = '適用済み取込が同日の成功分ではない';
                }
                $latest = $latestByDay->get($day);
                if ($classification === 'three_file') {
                    if ($latest === null) {
                        $reasons[] = '同日の成功分なし（取込待ち）';
                    } elseif ($survey->amazon_inventory_import_id !== $latest->id) {
                        $reasons[] = '同日の最新成功分が未適用';
                    }
                }

                $summary[$classification]++;
                $rows[] = [
                    'surveyId' => $survey->id,
                    'surveyDate' => $day,
                    'classification' => $classification,
                    'reasons' => $reasons,
                    'fileTypes' => $files,
                    'skuCount' => $skuCount,
                    'nonzeroAmazonSkuCount' => $nonzeroCount,
                    'storedAmazonOwnTotal' => (int) $stocks->own_total,
                    'storedAmazonFbaTotal' => (int) $stocks->fba_total,
                    'linkedImportId' => $survey->amazon_inventory_import_id,
                    'linkedImportDate' => $linkedDay,
                    'latestSuccessfulImportId' => $latest?->id,
                ];
            }
        });

        $dailySuccesses = $successByDay->map(fn ($daily): int => $daily->count())->sortKeys()->all();
        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $summary,
                'successfulImportsByDay' => $dailySuccesses,
                'surveys' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['旧5ファイル', '3ファイル', '判定不能'], [array_values($summary)]);
            $this->info('日別の成功取込件数: '.json_encode($dailySuccesses, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->table(['調査ID', '日付', '判定', '確認事項'], array_map(
                fn (array $row): array => [$row['surveyId'], $row['surveyDate'], $row['classification'], implode('、', $row['reasons'])],
                array_values(array_filter($rows, fn (array $row): bool => $row['classification'] === 'ambiguous' || $row['reasons'] !== [])),
            ));
            $this->comment('全件の判定・保存数量は --json で取得できます。DBは変更していません。');
        }

        return self::SUCCESS;
    }
}
