<?php

namespace App\Services;

use App\Models\Survey;
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
                $this->writeRow($stream, array_map(fn (string $header): string|int => $row[$header], self::HEADERS));
            }
        } finally {
            fclose($stream);
        }

        return $temporaryPath;
    }

    /** @return array<int, array<string, string|int>> */
    public function rows(?string $productCode, CarbonImmutable $from, CarbonImmutable $to): array
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

        $rows = [];
        foreach ($dailyProducts as $date => $products) {
            foreach ($products as $product) {
                foreach ($product->skus as $sku) {
                    $rows[] = [
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

        return $rows;
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
