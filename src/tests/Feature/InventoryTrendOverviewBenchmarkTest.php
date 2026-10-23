<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 在庫推移の品番一覧（docs/在庫推移画面の見直し TRD-01）の計測。200品番・2,000 SKU の調査を毎日1件作り、
 * 表示期間ごとに一覧の API の所要時間・SQLの数・PHPのピークメモリ・応答の大きさを測って表にする。目安は 90日で1秒以内。
 *
 * 通常のテストでは飛ばす。計測するとき: RUN_BENCHMARKS=1 php artisan test tests/Feature/InventoryTrendOverviewBenchmarkTest.php
 * テスト用のDB（インメモリSQLite）を使い、設定先のDBには触れない。
 */
class InventoryTrendOverviewBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_COUNT = 200;

    private const SKUS_PER_PRODUCT = 10;

    private const DAYS = 180;

    private const LAST_DATE = '2026-10-23';

    private int $queries = 0;

    private float $queryMs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('RUN_BENCHMARKS')) {
            $this->markTestSkipped('在庫推移の品番一覧の計測は RUN_BENCHMARKS=1 のときだけ実行する。');
        }
        ini_set('memory_limit', '2G');
        DB::listen(function (QueryExecuted $query): void {
            $this->queries++;
            $this->queryMs += $query->time;
        });
    }

    public function test_measure_inventory_trend_overview(): void
    {
        $this->seedHistory();
        $this->actingAs(User::factory()->create());
        $to = CarbonImmutable::parse(self::LAST_DATE);
        $lines = [
            '| 表示期間 | 所要時間 | SQL | SQLの時間 | 応答の大きさ | ピークメモリ |',
            '| --- | ---: | ---: | ---: | ---: | ---: |',
        ];

        foreach ([30, 90, self::DAYS] as $days) {
            gc_collect_cycles();
            $this->queries = 0;
            $this->queryMs = 0;
            $baseMemory = memory_get_usage();
            memory_reset_peak_usage();
            $startedAt = hrtime(true);
            $response = $this->getJson('/api/inventory-trends/overview?'.http_build_query([
                'from' => $to->subDays($days - 1)->toDateString(),
                'to' => $to->toDateString(),
                'scope' => 'grandTotal',
            ]))->assertOk();
            $lines[] = sprintf(
                '| %d日 | %s ms | %d | %s ms | %s MB | +%s MB |',
                $days,
                number_format((hrtime(true) - $startedAt) / 1e6),
                $this->queries,
                number_format($this->queryMs),
                number_format(strlen((string) $response->getContent()) / 1e6, 2),
                number_format((memory_get_peak_usage() - $baseMemory) / 1e6, 1),
            );
            $this->assertSame(self::PRODUCT_COUNT, $response->json('summary.productCount'));
        }

        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);
        if (is_string($path = env('BENCHMARK_OUTPUT')) && $path !== '') {
            file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        }
    }

    /** 毎日9時の SP-API 調査を、DB へ直接まとめて入れる。在庫は日と SKU で決まる値にし、一部を0（欠品）と NULL（取込待ち）にする */
    private function seedHistory(): void
    {
        $first = CarbonImmutable::parse(self::LAST_DATE)->subDays(self::DAYS - 1);
        for ($day = 0; $day < self::DAYS; $day++) {
            $executedAt = $first->addDays($day)->setTime(9, 0);
            $surveyId = DB::table('surveys')->insertGetId([
                'executed_at' => $executedAt, 'survey_date' => $executedAt->toDateString(), 'amazon_source' => 'sp_api',
                'includes_amazon_inventory' => false, 'created_at' => $executedAt, 'updated_at' => $executedAt,
            ]);
            $products = [];
            for ($index = 1; $index <= self::PRODUCT_COUNT; $index++) {
                $products[] = [
                    'survey_id' => $surveyId, 'product_code' => sprintf('P%04d', $index), 'brand' => 'BRAND'.($index % 5),
                    'category' => 'CATEGORY', 'status' => 'active', 'sort_order' => $index,
                    'group_memberships' => json_encode($index % 3 === 0 ? [['key' => 'setting:1', 'name' => 'G', 'sortOrder' => $index]] : []),
                    'created_at' => $executedAt, 'updated_at' => $executedAt,
                ];
            }
            DB::table('survey_products')->insert($products);
            $productIds = DB::table('survey_products')->where('survey_id', $surveyId)->orderBy('sort_order')->pluck('id', 'product_code');
            $skus = [];
            foreach ($productIds as $productCode => $productId) {
                for ($order = 1; $order <= self::SKUS_PER_PRODUCT; $order++) {
                    $seed = crc32($productCode.$order.$day);
                    $quantity = $seed % 7 === 0 ? 0 : $seed % 40;
                    $skus[] = [
                        'survey_product_id' => $productId, 'sku_code' => sprintf('%s-%02d', $productCode, $order),
                        'child_asin' => 'B'.str_pad((string) ($seed % 1_000_000_000), 9, '0', STR_PAD_LEFT), 'status' => 'active',
                        'tq_item_no' => $productCode, 'tq_color_no' => '01', 'tq_size' => 'S'.$order, 'sort_order' => $order,
                        'amazon_own_stock' => $day === self::DAYS - 1 && $seed % 11 === 0 ? null : $quantity,
                        'amazon_fba_stock' => $quantity, 'boss_own_stock' => $quantity, 'boss_rfc_stock' => 0,
                        'free_stock' => $quantity, 'ec_stock' => 0,
                        'created_at' => $executedAt, 'updated_at' => $executedAt,
                    ];
                }
            }
            foreach (array_chunk($skus, 500) as $chunk) {
                DB::table('survey_skus')->insert($chunk);
            }
        }
    }
}
