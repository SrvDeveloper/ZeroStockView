<?php

namespace App\Services;

use App\Models\AmazonInventoryImport;
use App\Models\Product;
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
                $this->writeRow($stream, array_map(fn (string $header): string|int|null => $row[$header], self::HEADERS));
            }
        } finally {
            fclose($stream);
        }

        return $temporaryPath;
    }

    /** @return array<int, array<string, string|int|null>> */
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

        $imports = AmazonInventoryImport::query()
            ->where('status', 'succeeded')
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('snapshots')
            ->with(['snapshots' => function ($query) use ($productCode): void {
                if ($productCode !== null) {
                    $query->where('product_code', $productCode);
                }
            }])
            ->orderBy('basis_at')->orderBy('completed_at')->orderBy('id')->get()
            ->keyBy(fn (AmazonInventoryImport $import): string => $import->snapshot_date->toDateString());
        $importProducts = [];
        foreach ($imports as $date => $import) {
            foreach ($import->snapshots as $snapshot) {
                if (! isset($dailyProducts[$date][$snapshot->product_code])) {
                    $importProducts[$date][$snapshot->product_code][] = $snapshot;
                }
            }
        }
        $productMetadata = Product::query()->whereIn('product_code', collect($importProducts)
            ->flatMap(fn (array $products): array => array_keys($products))->unique())
            ->with('skus')->get()->keyBy('product_code');

        $rows = [];
        $dates = array_unique(array_merge(array_keys($dailyProducts), array_keys($importProducts)));
        sort($dates);
        foreach ($dates as $date) {
            $products = array_unique(array_merge(array_keys($dailyProducts[$date] ?? []), array_keys($importProducts[$date] ?? [])));
            sort($products);
            foreach ($products as $code) {
                if (isset($dailyProducts[$date][$code])) {
                    $product = $dailyProducts[$date][$code];
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

                    continue;
                }

                $product = $productMetadata->get($code);
                $skus = $product?->skus->keyBy('sku_code');
                $snapshots = collect($importProducts[$date][$code])->sort(function ($left, $right) use ($skus): int {
                    $leftOrder = $skus?->get($left->sku_code)?->sort_order ?? PHP_INT_MAX;
                    $rightOrder = $skus?->get($right->sku_code)?->sort_order ?? PHP_INT_MAX;

                    return ($leftOrder <=> $rightOrder) ?: strcmp($left->sku_code, $right->sku_code);
                });
                foreach ($snapshots as $snapshot) {
                    $rows[] = [
                        'date' => $date,
                        'productCode' => $code,
                        'brand' => $product?->brand,
                        'category' => $product?->category,
                        'sku' => $snapshot->sku_code,
                        'size' => $skus?->get($snapshot->sku_code)?->tq_size,
                        'amazonOwn' => $snapshot->amazon_own_stock,
                        'amazonFba' => $snapshot->amazon_fba_stock,
                        'bossOwn' => null,
                        'bossRfc' => null,
                        'freeStock' => null,
                        'ecStock' => null,
                    ];
                }
            }
        }

        return $rows;
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
