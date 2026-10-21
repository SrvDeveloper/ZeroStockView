<?php

namespace App\Services;

use App\Models\Survey;
use App\Models\SurveyProduct;
use Carbon\CarbonImmutable;
use RuntimeException;

class InventoryTrendCsvExporter
{
    private const HEADERS = [
        'date', 'productCode', 'brand', 'category', 'sku', 'size',
        'amazonOwn', 'amazonFba', 'bossOwn', 'bossRfc', 'freeStock', 'ecStock',
    ];

    public function export(?string $productCode, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'inventory-trend-csv-');
        if ($temporaryPath === false) {
            throw new RuntimeException('CSVファイルを作成できませんでした。');
        }

        $stream = fopen($temporaryPath, 'wb');
        if ($stream === false) {
            throw new RuntimeException('CSVファイルを作成できませんでした。');
        }

        try {
            $this->writeRow($stream, self::HEADERS);
            foreach ($this->rows($productCode, $from, $to) as $row) {
                $this->writeRow($stream, array_map(fn (string $header): string|int|null => $row[$header], self::HEADERS));
            }
        } finally {
            fclose($stream);
        }

        return $temporaryPath;
    }

    /**
     * 日別・品番別・SKU別の在庫の行を、日付順に1日分ずつ読み込んで返す（期間全体を一度にメモリに持たない）。
     * 同じ日に調査が複数あれば、品番ごとに最後の調査を使う。調査の値だけを使い、Amazon在庫のスナップショットは見ない。
     *
     * @return \Generator<int, array<string, string|int|null>>
     */
    public function rows(?string $productCode, CarbonImmutable $from, CarbonImmutable $to): \Generator
    {
        $surveyQuery = Survey::query()
            ->whereBetween('executed_at', [$from->startOfDay(), $to->endOfDay()]);

        if ($productCode === null) {
            $surveyQuery->whereHas('products');
        } else {
            $surveyQuery->whereHas('products', fn ($query) => $query->where('product_code', $productCode));
        }

        $surveyIds = [];
        foreach ($surveyQuery->orderBy('executed_at')->orderBy('created_at')->orderBy('id')->get(['id', 'executed_at']) as $survey) {
            $surveyIds[$survey->executed_at->toDateString()][] = $survey->id;
        }

        foreach ($surveyIds as $date => $ids) {
            $products = SurveyProduct::query()
                ->whereIn('survey_id', $ids)
                ->when($productCode !== null, fn ($query) => $query->where('product_code', $productCode))
                ->with(['skus' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
                ->get()
                ->groupBy('survey_id');
            $dailyProducts = [];
            foreach ($ids as $surveyId) {
                foreach ($products->get($surveyId, []) as $product) {
                    $dailyProducts[$product->product_code] = $product;
                }
            }

            ksort($dailyProducts);
            foreach ($dailyProducts as $product) {
                foreach ($product->skus as $sku) {
                    yield [
                        'date' => $date,
                        'productCode' => $product->product_code,
                        'brand' => $product->brand,
                        'category' => $product->category,
                        'sku' => $sku->sku_code,
                        'size' => $sku->tq_size,
                        'amazonOwn' => $sku->amazon_own_stock,
                        'amazonFba' => $sku->amazon_fba_stock,
                        'bossOwn' => $sku->boss_own_stock,
                        'bossRfc' => $sku->boss_rfc_stock,
                        'freeStock' => $sku->free_stock,
                        'ecStock' => $sku->ec_stock,
                    ];
                }
            }
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<int, string|int|null>  $row
     */
    private function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('CSVファイルを作成できませんでした。');
        }
    }
}
