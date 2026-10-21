<?php

namespace Tests\Feature;

use App\Models\ApiConnectionSetting;
use App\Models\Survey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 在庫推移の出力の計測（docs/性能改善 PERF-11）。本番と同じ規模（112品番・817 SKU、ASINあり559 SKU）の調査を
 * 毎日1件（日曜は調査なしでAmazon取込だけ）作り、期間ごとにCSV出力と外部日次APIの所要時間・SQLの数・PHPのピークメモリ・
 * 応答の大きさを測って表にする。
 *
 * 通常のテストでは飛ばす。計測するとき: RUN_BENCHMARKS=1 php artisan test tests/Feature/InventoryTrendExportBenchmarkTest.php
 * テスト用のDB（インメモリSQLite）を使い、設定先のDBには触れない。
 */
class InventoryTrendExportBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_COUNT = 112;

    private const SKU_COUNT = 817;

    private const ASIN_SKU_COUNT = 559;

    private const DAYS = 365;

    private const LAST_DATE = '2026-10-20';

    private const API_KEY = 'zsv_test_api_key_1234567890';

    private int $queries = 0;

    private float $queryMs = 0;

    /** @var list<array<string, int|float|string>> */
    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('RUN_BENCHMARKS')) {
            $this->markTestSkipped('在庫推移の出力の計測は RUN_BENCHMARKS=1 のときだけ実行する。');
        }
        ini_set('memory_limit', '2G');
        DB::listen(function (QueryExecuted $query): void {
            $this->queries++;
            $this->queryMs += $query->time;
        });
    }

    public function test_measure_inventory_trend_exports(): void
    {
        $this->seedHistory();
        $this->actingAs(User::factory()->create());
        ApiConnectionSetting::query()->create([
            'enabled' => true,
            'api_key_hash' => hash('sha256', self::API_KEY),
            'api_key_suffix' => substr(self::API_KEY, -4),
            'api_key_issued_at' => now(),
            'allowed_networks' => [['network' => '203.0.113.0/24', 'memo' => '']],
        ]);
        $to = CarbonImmutable::parse(self::LAST_DATE);

        foreach ([30, 90, self::DAYS] as $days) {
            $query = http_build_query(['from' => $to->subDays($days - 1)->toDateString(), 'to' => $to->toDateString()]);
            $this->measure("CSV（全品番、{$days}日）", function () use ($query): array {
                $response = $this->get('/api/inventory-trends/export?'.$query)->assertOk()->baseResponse;
                $this->assertInstanceOf(BinaryFileResponse::class, $response);
                $path = $response->getFile()->getPathname();
                $bytes = (int) filesize($path);
                $lines = 0;
                $stream = fopen($path, 'rb');
                while (fgets($stream) !== false) {
                    $lines++;
                }
                fclose($stream);
                @unlink($path);

                return [$lines - 1, $bytes];
            });
            $this->measure("外部日次API（全品番、{$days}日）", function () use ($query): array {
                $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
                    ->withHeader('X-API-Key', self::API_KEY)
                    ->get('/api/v1/inventory/daily?'.$query)->assertOk()->baseResponse;

                return $this->drain($response);
            });
        }

        $this->report();
        $this->assertNotEmpty($this->results);
    }

    /**
     * 応答の本文を、ためずに読み流して大きさを数える（計測する側でメモリを使わないため）。
     * 以前の response()->json() の応答も同じように読む。
     *
     * @return array{0: int, 1: int} 行数（meta.count）と大きさ（バイト）
     */
    private function drain(Response $response): array
    {
        $bytes = 0;
        $tail = '';
        ob_start(function (string $buffer) use (&$bytes, &$tail): string {
            $bytes += strlen($buffer);
            $tail = substr($tail.$buffer, -200);

            return '';
        }, 8192);
        $response->sendContent();
        ob_end_clean();
        preg_match('/"count":(\d+)/', $tail, $matches);

        return [(int) ($matches[1] ?? 0), $bytes];
    }

    /** @param callable(): array{0: int, 1: int} $run 行数と応答の大きさ（バイト）を返す */
    private function measure(string $label, callable $run): void
    {
        gc_collect_cycles();
        $this->queries = 0;
        $this->queryMs = 0;
        $baseMemory = memory_get_usage();
        memory_reset_peak_usage();
        $startedAt = hrtime(true);
        [$rows, $bytes] = $run();
        $this->results[] = [
            'label' => $label,
            'ms' => number_format((hrtime(true) - $startedAt) / 1e6),
            'queries' => $this->queries,
            'queryMs' => number_format($this->queryMs),
            'rows' => number_format($rows),
            'mb' => number_format($bytes / 1e6, 1),
            'peakMb' => number_format((memory_get_peak_usage() - $baseMemory) / 1e6, 1),
        ];
    }

    private function report(): void
    {
        $lines = [
            '| 経路 | 所要時間 | SQL | SQLの時間 | 行数 | 応答の大きさ | ピークメモリ |',
            '| --- | ---: | ---: | ---: | ---: | ---: | ---: |',
        ];
        foreach ($this->results as $row) {
            $lines[] = "| {$row['label']} | {$row['ms']} ms | {$row['queries']} | {$row['queryMs']} ms | {$row['rows']} | {$row['mb']} MB | +{$row['peakMb']} MB |";
        }
        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);
        if (is_string($path = env('BENCHMARK_OUTPUT')) && $path !== '') {
            file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        }
    }

    /**
     * 毎日9時の調査（日曜はなし）と、毎日9時のAmazon取込を、DBへ直接まとめて入れる。
     * 日曜は調査がないので、Amazonの在庫だけの行になる。
     */
    private function seedHistory(): void
    {
        $skusPerProduct = array_fill(0, self::PRODUCT_COUNT, intdiv(self::SKU_COUNT, self::PRODUCT_COUNT));
        for ($index = 0; $index < self::SKU_COUNT % self::PRODUCT_COUNT; $index++) {
            $skusPerProduct[$index]++;
        }
        $masterSkus = [];
        foreach ($skusPerProduct as $productIndex => $skuCount) {
            $productCode = sprintf('P%03d', $productIndex + 1);
            $productId = DB::table('products')->insertGetId([
                'product_code' => $productCode, 'brand' => 'BRAND', 'category' => 'CATEGORY', 'status' => 'active',
                'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            for ($order = 1; $order <= $skuCount; $order++) {
                $masterSkus[] = [
                    'product_id' => $productId, 'sku_code' => sprintf('%s-%02d', $productCode, $order), 'status' => 'active',
                    'tq_item_no' => 'T'.$productCode, 'tq_color_no' => '01', 'tq_size' => 'S'.$order, 'sort_order' => $order,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($masterSkus, 400) as $chunk) {
            DB::table('skus')->insert($chunk);
        }
        $first = CarbonImmutable::parse(self::LAST_DATE)->subDays(self::DAYS - 1);
        for ($day = 0; $day < self::DAYS; $day++) {
            $date = $first->addDays($day);
            $executedAt = $date->setTime(9, 0);
            $importId = DB::table('amazon_inventory_imports')->insertGetId([
                'status' => 'succeeded', 'trigger_source' => 'schedule', 'source' => 'sp_api',
                'basis_at' => $executedAt, 'completed_at' => $executedAt, 'snapshot_date' => $date->toDateString(),
                'created_at' => $executedAt, 'updated_at' => $executedAt,
            ]);
            $snapshots = [];
            $surveyId = null;
            if (! $date->isSunday()) {
                $surveyId = DB::table('surveys')->insertGetId([
                    'executed_at' => $executedAt, 'survey_date' => $date->toDateString(), 'import_setting_name' => '全品番',
                    'includes_amazon_inventory' => true, 'amazon_source' => Survey::AMAZON_SOURCE_SP_API, 'amazon_inventory_import_id' => $importId,
                    'created_at' => $executedAt, 'updated_at' => $executedAt,
                ]);
            }
            $skuIndex = 0;
            $skus = [];
            foreach ($skusPerProduct as $productIndex => $skuCount) {
                $productCode = sprintf('P%03d', $productIndex + 1);
                $productId = $surveyId === null ? null : DB::table('survey_products')->insertGetId([
                    'survey_id' => $surveyId, 'product_code' => $productCode, 'brand' => 'BRAND', 'category' => 'CATEGORY',
                    'status' => 'active', 'sort_order' => $productIndex + 1, 'created_at' => $executedAt, 'updated_at' => $executedAt,
                ]);
                for ($order = 1; $order <= $skuCount; $order++, $skuIndex++) {
                    $skuCode = sprintf('%s-%02d', $productCode, $order);
                    $hasAsin = $skuIndex < self::ASIN_SKU_COUNT;
                    $own = ($skuIndex + $day) % 7;
                    $fba = ($skuIndex * 3 + $day) % 11;
                    if ($productId !== null) {
                        $skus[] = [
                            'survey_product_id' => $productId, 'sku_code' => $skuCode, 'child_asin' => $hasAsin ? sprintf('B%09d', $skuIndex) : null,
                            'status' => 'active', 'tq_item_no' => 'T'.$productCode, 'tq_color_no' => '01', 'tq_size' => 'S'.$order, 'sort_order' => $order,
                            'amazon_own_stock' => $hasAsin ? $own : null, 'amazon_fba_stock' => $hasAsin ? $fba : null,
                            'boss_own_stock' => $skuIndex % 13, 'boss_rfc_stock' => $skuIndex % 5, 'free_stock' => $skuIndex % 17, 'ec_stock' => $skuIndex % 3,
                            'created_at' => $executedAt, 'updated_at' => $executedAt,
                        ];
                    }
                    if ($hasAsin) {
                        $snapshots[] = [
                            'amazon_inventory_import_id' => $importId, 'product_code' => $productCode, 'sku_code' => $skuCode,
                            'asin' => sprintf('B%09d', $skuIndex), 'amazon_own_stock' => $own, 'amazon_fba_stock' => $fba,
                            'source' => 'sp_api', 'basis_at' => $executedAt, 'created_at' => $executedAt, 'updated_at' => $executedAt,
                        ];
                    }
                }
            }
            foreach (array_chunk($skus, 400) as $chunk) {
                DB::table('survey_skus')->insert($chunk);
            }
            foreach (array_chunk($snapshots, 400) as $chunk) {
                DB::table('amazon_inventory_snapshots')->insert($chunk);
            }
        }
    }
}
