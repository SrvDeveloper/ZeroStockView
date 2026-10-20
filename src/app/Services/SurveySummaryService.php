<?php

namespace App\Services;

use App\Models\Survey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 調査履歴の一覧に出す集計値を、SKUを読み込まずに集計クエリで求める。
 * 全倉庫ゼロ・一部ゼロの判定は画面の utils/stock.ts（skuTotal・isAllZero・isPartialZero）と同じ規則にする。
 * 未確定（null）の倉庫がある SKU は総数を出さず、どちらにも数えない。
 */
class SurveySummaryService
{
    /**
     * Amazon在庫の扱いごとの数え方。どれを使うかは調査ごとに決まる（summarize を参照）。
     * sp_api: ASINのないSKUはAmazonを対象外（0）として数える。saved: 調査に保存したAmazon在庫を使う。none: Amazonを数えない。
     */
    private const MODES = ['sp_api', 'saved', 'none'];

    /**
     * @param  Collection<int, Survey>  $surveys
     * @return array<int, array{productCount: int, skuCount: int, allZeroCount: int, partialZeroCount: int, hasSavedAmazonInventory: bool, amazonInventoryStatus: string}>
     */
    public function summarize(Collection $surveys): array
    {
        if ($surveys->isEmpty()) {
            return [];
        }
        $ids = $surveys->map(fn (Survey $survey): int => $survey->getKey())->all();

        $productCounts = DB::table('survey_products')
            ->whereIn('survey_id', $ids)
            ->groupBy('survey_id')
            ->selectRaw('survey_id, COUNT(*) AS product_count')
            ->pluck('product_count', 'survey_id');

        $skuStats = DB::table('survey_skus AS k')
            ->join('survey_products AS p', 'p.id', '=', 'k.survey_product_id')
            ->whereIn('p.survey_id', $ids)
            ->groupBy('p.survey_id')
            ->selectRaw('p.survey_id, COUNT(*) AS sku_count, '.implode(', ', $this->aggregates()))
            ->get()
            ->keyBy('survey_id');

        $summaries = [];
        foreach ($surveys as $survey) {
            $stats = $skuStats->get($survey->getKey());
            $count = fn (string $column): int => (int) ($stats->{$column} ?? 0);
            $hasSaved = $survey->resolveHasSavedAmazonInventory(fn (): bool => $count('amazon_positive') > 0);
            $mode = $survey->amazon_source === Survey::AMAZON_SOURCE_SP_API ? 'sp_api' : ($hasSaved ? 'saved' : 'none');

            $summaries[$survey->getKey()] = [
                'productCount' => (int) ($productCounts->get($survey->getKey()) ?? 0),
                'skuCount' => $count('sku_count'),
                'allZeroCount' => $count("all_zero_{$mode}"),
                'partialZeroCount' => $count("partial_zero_{$mode}"),
                'hasSavedAmazonInventory' => $hasSaved,
                'amazonInventoryStatus' => $survey->resolveAmazonInventoryStatus(fn (): bool => $count('amazon_incomplete') > 0, fn (): bool => $hasSaved),
            ];
        }

        return $summaries;
    }

    /** @return list<string> */
    private function aggregates(): array
    {
        $own = 'k.amazon_own_stock';
        $fba = 'k.amazon_fba_stock';
        $asinBlank = "(k.child_asin IS NULL OR TRIM(k.child_asin) = '')";
        $amazonKnown = "({$own} IS NOT NULL AND {$fba} IS NOT NULL)";
        $othersKnown = '(k.boss_own_stock IS NOT NULL AND k.boss_rfc_stock IS NOT NULL AND k.free_stock IS NOT NULL AND k.ec_stock IS NOT NULL)';
        $othersTotal = '(k.boss_own_stock + k.boss_rfc_stock + k.free_stock + k.ec_stock)';
        $otherZero = '(k.boss_own_stock + k.boss_rfc_stock = 0 OR k.free_stock = 0 OR k.ec_stock = 0)';

        // 調査の扱いごとに、総数が確定する条件・総数・「0の倉庫がある」の条件
        $rules = [
            // ASINのないSKUは、Amazon在庫がなくても対象外（0）として総数を出す。0の倉庫の判定にはAmazonを含めない
            'sp_api' => [
                "{$othersKnown} AND ({$amazonKnown} OR {$asinBlank})",
                "{$othersTotal} + CASE WHEN {$amazonKnown} THEN {$own} + {$fba} ELSE 0 END",
                "((NOT {$asinBlank}) AND {$own} + {$fba} = 0) OR {$otherZero}",
            ],
            'saved' => [
                "{$othersKnown} AND {$amazonKnown}",
                "{$othersTotal} + {$own} + {$fba}",
                "{$own} + {$fba} = 0 OR {$otherZero}",
            ],
            'none' => [$othersKnown, $othersTotal, $otherZero],
        ];

        $columns = [];
        foreach (self::MODES as $mode) {
            [$known, $total, $hasZero] = $rules[$mode];
            $columns[] = "SUM(CASE WHEN {$known} AND {$total} = 0 THEN 1 ELSE 0 END) AS all_zero_{$mode}";
            $columns[] = "SUM(CASE WHEN {$known} AND {$total} > 0 AND ({$hasZero}) THEN 1 ELSE 0 END) AS partial_zero_{$mode}";
        }
        // 旧方式の判定（Amazon在庫が1以上のSKUがある）と、SP-API調査の一部未取得の判定（Survey::amazonInventoryStatus と同じ）
        $columns[] = "SUM(CASE WHEN {$own} > 0 OR {$fba} > 0 THEN 1 ELSE 0 END) AS amazon_positive";
        $columns[] = "SUM(CASE WHEN ((NOT {$asinBlank}) OR {$own} IS NOT NULL OR {$fba} IS NOT NULL) AND ({$own} IS NULL OR {$fba} IS NULL) THEN 1 ELSE 0 END) AS amazon_incomplete";

        return $columns;
    }
}
