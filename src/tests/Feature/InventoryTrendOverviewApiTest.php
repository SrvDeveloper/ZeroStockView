<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Models\User;
use App\Services\InventoryTrendOverviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InventoryTrendOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST = '2026-10-23';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_points_match_the_product_trend_for_every_scope(): void
    {
        $skus = fn (array $s3Boss, ?int $s1Own): array => [
            'P-1-S1' => $this->sku(own: $s1Own, fba: 2, bossOwn: 3, bossRfc: 4, free: 5, stock: 6),
            'P-1-S2' => $this->sku(own: null, fba: null, bossOwn: 1, bossRfc: 1, free: 0, stock: 2, asin: null),
            'P-1-S3' => $this->sku(own: 7, fba: 0, bossOwn: $s3Boss[0], bossRfc: $s3Boss[1], free: 1, stock: 0),
        ];
        Product::factory()->create(['product_code' => 'P-1']);
        $this->survey('2026-10-20', ['P-1' => $skus([2, 2], 1)]);
        $this->survey('2026-10-21', ['P-1' => $skus([null, 2], 1)]);
        // 10/22 は調査なし
        $this->survey('2026-10-23', ['P-1' => $skus([0, 0], null)]);

        $warehouses = [
            'mallTotal' => ['amazon', 'boss'],
            'amazon' => ['amazon'],
            'boss' => ['boss'],
            'free' => ['free'],
            'stock' => ['stock'],
            'grandTotal' => ['amazon', 'boss', 'free', 'stock'],
        ];
        foreach ($warehouses as $scope => $keys) {
            $trend = $this->getJson("/api/inventory-trends?product_code=P-1&from=2026-10-20&to=2026-10-23&scope={$scope}")->assertOk();
            $expected = collect($trend->json('data.warehouseTotals'))->map(function (array $totals) use ($keys): array {
                $values = array_map(fn (string $key): ?int => $totals[$key], $keys);

                return ['date' => $totals['date'], 'quantity' => in_array(null, $values, true) ? null : array_sum($values)];
            })->all();

            $this->overview(['from' => '2026-10-20', 'scope' => $scope])
                ->assertOk()
                ->assertJsonPath('data.0.productCode', 'P-1')
                ->assertJsonPath('data.0.points', $expected);
        }
    }

    public function test_pending_skus_are_not_stockouts_and_asin_less_skus_are_outside_the_amazon_scope(): void
    {
        $this->survey(self::LATEST, ['P-1' => [
            'P-1-S1' => $this->sku(own: 0, fba: 0, bossOwn: 0, bossRfc: 0),
            'P-1-S2' => $this->sku(own: null, fba: null, bossOwn: null, bossRfc: 3, asin: null),
            'P-1-S3' => $this->sku(own: 4, fba: 1, bossOwn: 5, bossRfc: 0),
        ]]);

        $this->overview(['scope' => 'boss'])
            ->assertJsonPath('data.0.skuStates', ['stockout', 'pending', 'inStock'])
            ->assertJsonPath('data.0.stockoutSkuCount', 1)
            ->assertJsonPath('data.0.skuCount', 3)
            ->assertJsonPath('data.0.latest', ['date' => self::LATEST, 'quantity' => null])
            ->assertJsonPath('summary.totalQuantity', null);

        // ASIN のない SP-API 調査の SKU は Amazon 取得の対象外なので、Amazon の区分では数えない
        $this->overview(['scope' => 'amazon'])
            ->assertJsonPath('data.0.skuStates', ['stockout', 'inStock'])
            ->assertJsonPath('data.0.skuCount', 2)
            ->assertJsonPath('data.0.latest.quantity', 5);
        // ほかの区分では Amazon を 0 として合計する
        $this->overview(['scope' => 'mallTotal'])
            ->assertJsonPath('data.0.skuStates', ['stockout', 'pending', 'inStock']);
    }

    public function test_week_ago_uses_the_survey_seven_days_before_or_the_nearest_earlier_one_within_fourteen_days(): void
    {
        $this->survey('2026-10-03', ['C' => ['C-1' => $this->sku(bossOwn: 9)]]);
        $this->survey('2026-10-08', ['C' => ['C-1' => $this->sku(bossOwn: 8)]]);
        $this->survey('2026-10-14', ['B' => ['B-1' => $this->sku(bossOwn: 7)]]);
        $this->survey('2026-10-16', ['A' => ['A-1' => $this->sku(bossOwn: 6)]]);
        $this->survey(self::LATEST, [
            'A' => ['A-1' => $this->sku(bossOwn: 3)],
            'B' => ['B-1' => $this->sku(bossOwn: 14)],
            'C' => ['C-1' => $this->sku(bossOwn: 1)],
        ]);

        $response = $this->overview(['scope' => 'boss', 'from' => '2026-10-20', 'sort' => 'code']);
        $response->assertJsonPath('data.0.weekAgo', ['date' => '2026-10-16', 'quantity' => 7])
            ->assertJsonPath('data.1.weekAgo', ['date' => '2026-10-14', 'quantity' => 8])
            // 7日前も、それより前の14日前までも調査がなければ比べない（10/08 は15日前）
            ->assertJsonPath('data.2.weekAgo', null);
    }

    public function test_focus_sort_group_brand_search_and_summary(): void
    {
        $this->survey('2026-09-10', ['LONG' => ['LONG-1' => $this->sku(bossOwn: 5), 'LONG-2' => $this->sku(bossOwn: 5)]]);
        $this->survey('2026-09-12', ['LONG' => ['LONG-1' => $this->sku(bossOwn: 0, bossRfc: 0), 'LONG-2' => $this->sku(bossOwn: 5)]]);
        $this->survey('2026-10-16', [
            'DOWN' => ['DOWN-1' => $this->sku(bossOwn: 10, bossRfc: 10), 'DOWN-2' => $this->sku(bossOwn: 0, bossRfc: 0), 'DOWN-3' => $this->sku(bossOwn: 0, bossRfc: 0)],
            'FLAT' => ['FLAT-1' => $this->sku(bossOwn: 100, bossRfc: 0), 'FLAT-2' => $this->sku(bossOwn: 0, bossRfc: 0)],
            'UP' => ['UP-1' => $this->sku(bossOwn: 40, bossRfc: 0, asin: 'B0SEARCH01')],
            'LONG' => ['LONG-1' => $this->sku(bossOwn: 0, bossRfc: 0), 'LONG-2' => $this->sku(bossOwn: 20)],
        ], memberships: ['UP' => [['key' => 'setting:1', 'name' => '売上TOP20', 'sortOrder' => 1]]]);
        $this->survey(self::LATEST, [
            'DOWN' => ['DOWN-1' => $this->sku(bossOwn: 1, bossRfc: 0), 'DOWN-2' => $this->sku(bossOwn: 0, bossRfc: 0), 'DOWN-3' => $this->sku(bossOwn: 0, bossRfc: 0)],
            'FLAT' => ['FLAT-1' => $this->sku(bossOwn: 100, bossRfc: 0), 'FLAT-2' => $this->sku(bossOwn: 0, bossRfc: 0)],
            'UP' => ['UP-1' => $this->sku(bossOwn: 50, bossRfc: 0, asin: 'B0SEARCH01')],
            'LONG' => ['LONG-1' => $this->sku(bossOwn: 0, bossRfc: 0), 'LONG-2' => $this->sku(bossOwn: 19)],
        ], brands: ['UP' => 'BRAVO'], memberships: ['UP' => [['key' => 'setting:1', 'name' => '売上TOP20', 'sortOrder' => 1]]]);
        // 欠品が増えた品番を作るため、1週間前の DOWN の欠品を 1 SKU にする
        SurveySku::query()->where('sku_code', 'DOWN-3')
            ->whereHas('product.survey', fn ($query) => $query->whereDate('executed_at', '2026-10-16'))
            ->update(['boss_own_stock' => 3]);

        $response = $this->overview(['scope' => 'boss', 'from' => '2026-10-10']);
        $response->assertJsonPath('summary.productCount', 4)
            ->assertJsonPath('summary.skuCount', 8)
            ->assertJsonPath('summary.stockoutSkuCount', 4)
            ->assertJsonPath('summary.stockoutSkuCountWeekAgo', 3)
            ->assertJsonPath('summary.longStockoutSkuCount', 1)
            ->assertJsonPath('summary.longStockoutProductCount', 1)
            ->assertJsonPath('summary.totalQuantity', 171)
            ->assertJsonPath('summary.totalQuantityWeekAgo', 184)
            ->assertJsonPath('summary.focusCounts', ['all' => 4, 'stockout' => 3, 'increased' => 1, 'decreasing' => 1, 'long' => 1])
            // 初期の並び順は欠品SKUの多い順。同じ数なら前週比の小さい順
            ->assertJsonPath('data.*.productCode', ['DOWN', 'LONG', 'FLAT', 'UP'])
            ->assertJsonPath('data.1.longestStockoutStreak', 43)
            ->assertJsonPath('data.0.stockoutSkuDays', 3)
            ->assertJsonPath('data.3.groups', [['key' => 'setting:1', 'name' => '売上TOP20']])
            ->assertJsonPath('data.0.groups', [['key' => 'ungrouped', 'name' => 'グループなし']])
            ->assertJsonPath('groups', [['key' => 'setting:1', 'name' => '売上TOP20'], ['key' => 'ungrouped', 'name' => 'グループなし']])
            ->assertJsonPath('brands', ['ALPHA', 'BRAVO'])
            ->assertJsonPath('meta', ['page' => 1, 'perPage' => InventoryTrendOverviewService::PER_PAGE, 'total' => 4]);

        $codes = fn (array $params): array => $this->overview(['scope' => 'boss', 'from' => '2026-10-10'] + $params)->json('data.*.productCode');
        $this->assertSame(['DOWN', 'LONG', 'FLAT'], $codes(['focus' => 'stockout']));
        $this->assertSame(['DOWN'], $codes(['focus' => 'increased']));
        $this->assertSame(['DOWN'], $codes(['focus' => 'decreasing']));
        $this->assertSame(['LONG'], $codes(['focus' => 'long']));
        $this->assertSame(['DOWN', 'LONG', 'FLAT', 'UP'], $codes(['sort' => 'delta']));
        $this->assertSame(['DOWN', 'LONG', 'UP', 'FLAT'], $codes(['sort' => 'latest']));
        $this->assertSame(['DOWN', 'FLAT', 'LONG', 'UP'], $codes(['sort' => 'code']));
        $this->assertSame(['UP'], $codes(['group' => 'setting:1']));
        $this->assertSame(['DOWN', 'LONG', 'FLAT'], $codes(['group' => 'ungrouped']));
        $this->assertSame(['UP'], $codes(['brand' => 'BRAVO']));
        $this->assertSame(['UP'], $codes(['search' => 'SEARCH01']));
        $this->assertSame(['FLAT'], $codes(['search' => 'flat-2']));

        // 注目の絞り込みをかけても、上部の数値は変わらない
        $this->overview(['scope' => 'boss', 'from' => '2026-10-10', 'focus' => 'long'])
            ->assertJsonPath('summary.productCount', 4)
            ->assertJsonPath('summary.focusCounts.all', 4)
            ->assertJsonPath('meta.total', 1);
        // グループ・ブランド・検索の絞り込みは、上部の数値にもかかる
        $this->overview(['scope' => 'boss', 'from' => '2026-10-10', 'brand' => 'BRAVO'])
            ->assertJsonPath('summary.productCount', 1)
            ->assertJsonPath('summary.stockoutSkuCount', 0);
    }

    public function test_pages_hold_twenty_five_products(): void
    {
        $products = [];
        for ($index = 1; $index <= InventoryTrendOverviewService::PER_PAGE + 1; $index++) {
            $code = sprintf('P-%02d', $index);
            $products[$code] = [$code.'-1' => $this->sku()];
        }
        $this->survey(self::LATEST, $products);

        $this->overview(['scope' => 'boss', 'sort' => 'code', 'page' => 2])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.productCode', 'P-26')
            ->assertJsonPath('meta.total', 26);
    }

    public function test_stockout_streak_does_not_break_on_days_without_a_survey_or_pending_values_and_looks_back_before_the_period(): void
    {
        $this->survey('2026-10-03', ['A' => ['A-1' => $this->sku(bossOwn: 6)]]);
        $this->survey('2026-10-05', ['A' => ['A-1' => $this->sku(bossOwn: 0, bossRfc: 0)]]);
        $this->survey('2026-10-12', ['A' => ['A-1' => $this->sku(bossOwn: null, bossRfc: 0)]]);
        $this->survey(self::LATEST, ['A' => ['A-1' => $this->sku(bossOwn: 0, bossRfc: 0)]]);

        // 表示期間（10/20〜）より前の、最後に在庫があった 10/03 から数える
        $this->overview(['scope' => 'boss', 'from' => '2026-10-20'])
            ->assertJsonPath('data.0.longestStockoutStreak', 20)
            ->assertJsonPath('data.0.stockoutSkuDays', 1)
            ->assertJsonPath('summary.longStockoutSkuCount', 0);
    }

    public function test_stockout_streak_looks_back_at_most_ninety_days(): void
    {
        $this->survey('2026-07-01', ['A' => ['A-1' => $this->sku(bossOwn: 6)]]);
        $this->survey('2026-07-20', ['A' => ['A-1' => $this->sku(bossOwn: 0, bossRfc: 0)]]);
        $this->survey('2026-07-26', ['A' => ['A-1' => $this->sku(bossOwn: 0, bossRfc: 0)]]);
        $this->survey(self::LATEST, ['A' => ['A-1' => $this->sku(bossOwn: 0, bossRfc: 0)]]);

        // 10/23 の90日前（7/25）より前の在庫は見ず、範囲内で最初の 7/26 から数える
        $this->overview(['scope' => 'boss', 'from' => '2026-10-20'])
            ->assertJsonPath('data.0.longestStockoutStreak', 90)
            ->assertJsonPath('summary.longStockoutSkuCount', 1);
    }

    public function test_multiple_surveys_on_one_day_use_the_last_survey_that_has_the_product(): void
    {
        $this->survey(self::LATEST, ['A' => ['A-1' => $this->sku(bossOwn: 1)], 'B' => ['B-1' => $this->sku(bossOwn: 1)]], time: '08:00:00');
        $this->survey(self::LATEST, ['A' => ['A-1' => $this->sku(bossOwn: 5)]], time: '18:00:00');

        $this->overview(['scope' => 'boss', 'sort' => 'code'])
            ->assertJsonPath('data.0.latest.quantity', 6)
            ->assertJsonPath('data.1.latest.quantity', 2);
    }

    public function test_latest_survey_and_empty_period(): void
    {
        $this->survey(self::LATEST, ['A' => ['A-1' => $this->sku(own: null, fba: null)]]);

        $this->overview(['scope' => 'boss'])
            ->assertJsonPath('latestSurvey', ['date' => self::LATEST, 'amazonInventoryStatus' => 'pending', 'amazonBasisAt' => null])
            ->assertJsonPath('dateRange', ['from' => self::LATEST, 'to' => self::LATEST]);
        $this->overview(['scope' => 'boss', 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('latestSurvey', null)
            ->assertJsonPath('summary.productCount', 0)
            ->assertJsonPath('summary.totalQuantity', null);
    }

    public function test_requests_are_validated_and_require_login(): void
    {
        $this->getJson('/api/inventory-trends/overview?from=2026-10-01&to=2026-10-23')->assertUnprocessable()->assertJsonValidationErrors('scope');
        $this->overview(['focus' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('focus');
        $this->overview(['sort' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->overview(['from' => '2025-10-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->overview(['page' => 0])->assertUnprocessable()->assertJsonValidationErrors('page');

        auth()->logout();
        $this->overview([])->assertUnauthorized();
    }

    /** @param array<string, int|string|null> $params */
    private function overview(array $params): TestResponse
    {
        return $this->getJson('/api/inventory-trends/overview?'.http_build_query($params + [
            'from' => '2026-10-01',
            'to' => self::LATEST,
            'scope' => 'mallTotal',
        ]));
    }

    /** @return array<string, int|string|null> */
    private function sku(?int $own = 1, ?int $fba = 1, ?int $bossOwn = 1, ?int $bossRfc = 1, ?int $free = 1, ?int $stock = 1, ?string $asin = 'B000000001'): array
    {
        return [
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
            'boss_own_stock' => $bossOwn,
            'boss_rfc_stock' => $bossRfc,
            'free_stock' => $free,
            'ec_stock' => $stock,
            'child_asin' => $asin,
        ];
    }

    /**
     * @param  array<string, array<string, array<string, int|string|null>>>  $products  品番 => SKU コード => 在庫
     * @param  array<string, string>  $brands
     * @param  array<string, list<array{key: string, name: string, sortOrder: int}>>  $memberships  指定のない品番はグループなし
     */
    private function survey(string $date, array $products, array $brands = [], array $memberships = [], string $time = '09:00:00'): Survey
    {
        $survey = Survey::factory()->spApi()->create([
            'executed_at' => CarbonImmutable::parse("{$date} {$time}"),
            'import_setting_name' => null,
        ]);
        $productOrder = 0;
        foreach ($products as $productCode => $skus) {
            $product = SurveyProduct::factory()->for($survey)->create([
                'product_code' => $productCode,
                'brand' => $brands[$productCode] ?? 'ALPHA',
                'category' => 'トップス',
                'sort_order' => ++$productOrder,
                'group_memberships' => $memberships[$productCode] ?? [],
            ]);
            $skuOrder = 0;
            foreach ($skus as $skuCode => $stocks) {
                SurveySku::factory()->for($product, 'product')->create(['sku_code' => $skuCode, 'sort_order' => ++$skuOrder] + $stocks);
            }
        }

        return $survey;
    }
}
