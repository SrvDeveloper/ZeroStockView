<?php

namespace App\Services;

use App\Models\Survey;
use Carbon\CarbonImmutable;
use RuntimeException;

class InventoryTrendCsvExporter
{
    public function export(?string $productCode, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $surveyQuery = Survey::query()
            ->whereBetween('executed_at', [$from->startOfDay(), $to->endOfDay()]);

        if ($productCode === null) {
            $surveyQuery->whereHas('products');
        } else {
            $surveyQuery->whereHas('products', fn ($query) => $query->where('product_code', $productCode));
        }

        $surveys = $surveyQuery
            ->with(['products' => function ($query) use ($productCode): void {
                if ($productCode !== null) {
                    $query->where('product_code', $productCode);
                }

                $query->with(['skus' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
            }])
            ->orderBy('executed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $dailyProducts = [];
        foreach ($surveys as $survey) {
            $date = $survey->executed_at->toDateString();
            foreach ($survey->products as $product) {
                $dailyProducts[$date][$product->product_code] = $product;
            }
        }
        ksort($dailyProducts);
        foreach ($dailyProducts as &$products) {
            ksort($products);
        }
        unset($products);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'inventory-trend-csv-');
        if ($temporaryPath === false) {
            throw new RuntimeException('CSVファイルを作成できませんでした。');
        }

        $stream = fopen($temporaryPath, 'wb');
        if ($stream === false) {
            throw new RuntimeException('CSVファイルを作成できませんでした。');
        }

        try {
            $this->writeRow($stream, [
                'date', 'productCode', 'brand', 'category', 'sku', 'size',
                'amazonOwn', 'amazonFba', 'bossOwn', 'bossRfc', 'freeStock', 'ecStock',
            ]);

            foreach ($dailyProducts as $date => $products) {
                foreach ($products as $product) {
                    foreach ($product->skus as $sku) {
                        $this->writeRow($stream, [
                            $date,
                            $product->product_code,
                            $product->brand,
                            $product->category,
                            $sku->sku_code,
                            $sku->tq_size,
                            $sku->amazon_own_stock,
                            $sku->amazon_fba_stock,
                            $sku->boss_own_stock,
                            $sku->boss_rfc_stock,
                            $sku->free_stock,
                            $sku->ec_stock,
                        ]);
                    }
                }
            }
        } finally {
            fclose($stream);
        }

        return $temporaryPath;
    }

    /**
     * @param  resource  $stream
     * @param  array<int, string|int>  $row
     */
    private function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('CSVファイルを作成できませんでした。');
        }
    }
}
