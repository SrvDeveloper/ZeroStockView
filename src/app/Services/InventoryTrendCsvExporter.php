<?php

namespace App\Services;

use App\Models\AmazonInventoryImport;
use App\Models\AmazonInventorySnapshot;
use App\Models\Product;
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
     * 同じ日に調査が複数あれば、品番ごとに最後の調査を使う。調査にない品番は、その日の最後のAmazon取込の在庫だけの行にする。
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

        $importIds = AmazonInventoryImport::query()
            ->where('status', 'succeeded')
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('snapshots')
            ->orderBy('basis_at')->orderBy('completed_at')->orderBy('id')->get(['id', 'snapshot_date'])
            ->mapWithKeys(fn (AmazonInventoryImport $import): array => [$import->snapshot_date->toDateString() => $import->id])
            ->all();

        /** @var array<string, Product|null> $productMetadata 品番コード => 品番マスタ（Amazon在庫だけの行の品番だけ読む） */
        $productMetadata = [];
        $dates = array_unique(array_merge(array_keys($surveyIds), array_keys($importIds)));
        sort($dates);
        foreach ($dates as $date) {
            $dailyProducts = [];
            if (isset($surveyIds[$date])) {
                $products = SurveyProduct::query()
                    ->whereIn('survey_id', $surveyIds[$date])
                    ->when($productCode !== null, fn ($query) => $query->where('product_code', $productCode))
                    ->with(['skus' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
                    ->get()
                    ->groupBy('survey_id');
                foreach ($surveyIds[$date] as $surveyId) {
                    foreach ($products->get($surveyId, []) as $product) {
                        $dailyProducts[$product->product_code] = $product;
                    }
                }
            }

            $importProducts = [];
            if (isset($importIds[$date])) {
                $snapshots = AmazonInventorySnapshot::query()
                    ->where('amazon_inventory_import_id', $importIds[$date])
                    ->when($productCode !== null, fn ($query) => $query->where('product_code', $productCode))
                    ->whereNotIn('product_code', array_map(strval(...), array_keys($dailyProducts)))
                    ->get();
                foreach ($snapshots as $snapshot) {
                    $importProducts[$snapshot->product_code][] = $snapshot;
                }
            }
            $missingCodes = array_values(array_diff(array_map(strval(...), array_keys($importProducts)), array_keys($productMetadata)));
            if ($missingCodes !== []) {
                $loaded = Product::query()->whereIn('product_code', $missingCodes)->with('skus')->get()->keyBy('product_code');
                foreach ($missingCodes as $code) {
                    $productMetadata[$code] = $loaded->get($code);
                }
            }

            $codes = array_unique(array_merge(array_keys($dailyProducts), array_keys($importProducts)));
            sort($codes);
            foreach ($codes as $code) {
                if (isset($dailyProducts[$code])) {
                    $product = $dailyProducts[$code];
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

                    continue;
                }

                $product = $productMetadata[(string) $code];
                $skus = $product?->skus->keyBy('sku_code');
                $snapshots = collect($importProducts[$code])->sort(function ($left, $right) use ($skus): int {
                    $leftOrder = $skus?->get($left->sku_code)?->sort_order ?? PHP_INT_MAX;
                    $rightOrder = $skus?->get($right->sku_code)?->sort_order ?? PHP_INT_MAX;

                    return ($leftOrder <=> $rightOrder) ?: strcmp($left->sku_code, $right->sku_code);
                });
                foreach ($snapshots as $snapshot) {
                    yield [
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
