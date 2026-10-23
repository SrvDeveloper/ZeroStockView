<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Models\User;
use App\Services\InventoryTrendCsvExporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTrendApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_with_survey_history_can_be_filtered_by_brand_and_search(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create([
            'product_code' => 'A-1001',
            'brand' => 'ALPHA',
            'category' => 'トップス',
        ]);
        Sku::factory()->for($product)->create(['sku_code' => 'A-1001-M', 'sort_order' => 1]);
        Product::factory()->create(['product_code' => 'NO-HISTORY', 'brand' => 'ALPHA']);
        $this->snapshot('2026-09-27 10:00:00', 'A-1001', 'A-1001-M');

        $this->getJson('/api/inventory-trends/products?brand=ALPHA&search=A-1001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.productCode', 'A-1001')
            ->assertJsonPath('data.0.skuCount', 1)
            ->assertJsonPath('brands.0', 'ALPHA')
            ->assertJsonPath('dateRange.from', '2026-09-27')
            ->assertJsonPath('dateRange.to', '2026-09-27');
    }

    public function test_inventory_trends_use_the_latest_snapshot_per_day_and_requested_scope(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create(['product_code' => 'A-1001']);
        Sku::factory()->for($product)->create(['sku_code' => 'A-1001-M', 'sort_order' => 1]);

        $this->snapshot('2026-09-27 09:00:00', 'A-1001', 'A-1001-M', amazonOwn: 1, amazonFba: 2, bossOwn: 3, bossRfc: 4, free: 5, stock: 6);
        $this->snapshot('2026-09-27 18:00:00', 'A-1001', 'A-1001-M', amazonOwn: 2, amazonFba: 3, bossOwn: 4, bossRfc: 5, free: 6, stock: 7);
        $this->snapshot('2026-09-29 10:00:00', 'A-1001', 'A-1001-M', amazonOwn: 10, amazonFba: 20, bossOwn: 30, bossRfc: 40, free: 50, stock: 60);

        $expectedQuantities = [
            'mallTotal' => [14, 100],
            'amazon' => [5, 30],
            'boss' => [9, 70],
            'free' => [6, 50],
            'stock' => [7, 60],
            'grandTotal' => [27, 210],
        ];

        foreach ($expectedQuantities as $scope => [$firstQuantity, $lastQuantity]) {
            $this->getJson("/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-29&scope={$scope}")
                ->assertOk()
                ->assertJsonPath('data.product.productCode', 'A-1001')
                ->assertJsonPath('data.dates.0', '2026-09-27')
                ->assertJsonPath('data.dates.1', '2026-09-28')
                ->assertJsonPath('data.dates.2', '2026-09-29')
                ->assertJsonPath('data.series.0.skuCode', 'A-1001-M')
                ->assertJsonPath('data.series.0.points.0.quantity', $firstQuantity)
                ->assertJsonPath('data.series.0.points.1.quantity', null)
                ->assertJsonPath('data.series.0.points.2.quantity', $lastQuantity);
        }
    }

    public function test_inventory_trend_series_include_the_color_and_the_warehouse_breakdown_of_combined_scopes(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['product_code' => 'A-1001']);
        $survey = Survey::factory()->create(['executed_at' => '2026-09-28 10:00:00']);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001']);
        SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => 'A-1001-01-M',
            'child_asin' => 'B000000001',
            'tq_color_no' => '01',
            'tq_size' => 'M',
            'amazon_own_stock' => 1,
            'amazon_fba_stock' => 2,
            'boss_own_stock' => 3,
            'boss_rfc_stock' => 4,
            'free_stock' => 5,
            'ec_stock' => 6,
        ]);
        $url = '/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-28&scope=';

        $this->getJson($url.'mallTotal')
            ->assertOk()
            ->assertJsonPath('data.series.0.color', '01')
            ->assertJsonPath('data.series.0.size', 'M')
            ->assertJsonPath('data.series.0.points.1.warehouses', ['amazon' => 3, 'boss' => 7])
            ->assertJsonMissingPath('data.series.0.points.0.warehouses');
        $this->getJson($url.'grandTotal')
            ->assertOk()
            ->assertJsonPath('data.series.0.points.1.warehouses', ['amazon' => 3, 'boss' => 7, 'free' => 5, 'stock' => 6]);
        $this->getJson($url.'boss')
            ->assertOk()
            ->assertJsonMissingPath('data.series.0.points.1.warehouses');
    }

    public function test_inventory_trend_product_includes_the_latest_groups_and_sku_color_size_counts(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['product_code' => 'A-1001']);
        Product::factory()->create(['product_code' => 'B-2002']);
        $older = Survey::factory()->create(['executed_at' => '2026-09-27 10:00:00', 'import_setting_name' => null]);
        SurveyProduct::factory()->for($older)->create([
            'product_code' => 'A-1001',
            'group_memberships' => [['key' => 'setting:9', 'name' => '旧グループ', 'sortOrder' => 1]],
        ]);
        $latest = Survey::factory()->create(['executed_at' => '2026-09-28 10:00:00', 'import_setting_name' => null]);
        $grouped = SurveyProduct::factory()->for($latest)->create([
            'product_code' => 'A-1001',
            'sort_order' => 1,
            'group_memberships' => [
                ['key' => 'setting:1', 'name' => '売上TOP20', 'sortOrder' => 1],
                ['key' => 'setting:5', 'name' => '秋冬新作', 'sortOrder' => 1],
            ],
        ]);
        foreach ([['01', 'S'], ['01', 'M'], ['02', 'S'], ['02', 'M'], ['03', 'M']] as $order => [$color, $size]) {
            SurveySku::factory()->for($grouped, 'product')->create(['sku_code' => "A-1001-{$color}-{$size}", 'tq_color_no' => $color, 'tq_size' => $size, 'sort_order' => $order + 1]);
        }
        $ungrouped = SurveyProduct::factory()->for($latest)->create(['product_code' => 'B-2002', 'sort_order' => 2, 'group_memberships' => []]);
        SurveySku::factory()->for($ungrouped, 'product')->create(['sku_code' => 'B-2002-F', 'tq_color_no' => '01', 'tq_size' => '', 'sort_order' => 1]);

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-28&scope=mallTotal')
            ->assertOk()
            ->assertJsonPath('data.product.groups', [
                ['key' => 'setting:1', 'name' => '売上TOP20'],
                ['key' => 'setting:5', 'name' => '秋冬新作'],
            ])
            ->assertJsonPath('data.product.skuCount', 5)
            ->assertJsonPath('data.product.colorCount', 3)
            ->assertJsonPath('data.product.sizeCount', 2);

        $this->getJson('/api/inventory-trends?product_code=B-2002&from=2026-09-27&to=2026-09-28&scope=mallTotal')
            ->assertOk()
            ->assertJsonPath('data.product.groups', [['key' => 'ungrouped', 'name' => 'グループなし']])
            ->assertJsonPath('data.product.skuCount', 1)
            ->assertJsonPath('data.product.colorCount', 1)
            ->assertJsonPath('data.product.sizeCount', 0);
    }

    public function test_inventory_trends_prefer_the_newest_result_when_inventory_basis_times_match(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create(['product_code' => 'A-1001']);
        Sku::factory()->for($product)->create(['sku_code' => 'A-1001-M', 'sort_order' => 1]);

        $this->snapshot('2026-09-27 18:00:00', 'A-1001', 'A-1001-M', amazonOwn: 1, createdAt: '2026-09-28 10:00:00');
        $this->snapshot('2026-09-27 18:00:00', 'A-1001', 'A-1001-M', amazonOwn: 9, createdAt: '2026-09-29 10:00:00');

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-27&scope=amazon')
            ->assertOk()
            ->assertJsonPath('data.series.0.points.0.quantity', 10);
    }

    public function test_product_only_in_amazon_snapshots_has_no_inventory_history(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create(['product_code' => 'API-ONLY', 'brand' => 'API BRAND']);
        Sku::factory()->for($product)->create(['sku_code' => 'API-ONLY-M', 'tq_size' => 'M', 'sort_order' => 1]);
        $this->apiSnapshot('2026-09-27 09:00:00', 'API-ONLY', 'API-ONLY-M', 4, 6);

        // スナップショットは調査にAmazon在庫を写すためだけに使い、在庫推移の品番・期間・値には使わない
        $this->getJson('/api/inventory-trends/products')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('dateRange', null);
        $this->getJson('/api/inventory-trends?product_code=API-ONLY&from=2026-09-27&to=2026-09-27&scope=amazon')
            ->assertOk()
            ->assertJsonCount(0, 'data.series')
            ->assertJsonCount(0, 'data.dailySources');
        $this->assertSame([], iterator_to_array(app(InventoryTrendCsvExporter::class)->rows(
            'API-ONLY', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27'),
        ), false));
    }

    public function test_daily_sources_include_the_boss_source_of_each_survey(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create(['product_code' => 'A-1001']);
        Sku::factory()->for($product)->create(['sku_code' => 'A-1001-M', 'sort_order' => 1]);
        $this->snapshot('2026-09-27 10:00:00', 'A-1001', 'A-1001-M');
        $this->snapshot('2026-09-28 09:00:00', 'A-1001', 'A-1001-M');
        $this->snapshot('2026-09-29 10:00:00', 'A-1001', 'A-1001-M');
        Survey::query()->whereDate('executed_at', '2026-09-27')->update(['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        Survey::query()->whereDate('executed_at', '2026-09-28')->update(['boss_source' => Survey::BOSS_SOURCE_HISTORY, 'boss_basis_at' => '2026-09-28 09:00:00']);

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-29&scope=boss')
            ->assertOk()
            ->assertJsonPath('data.dailySources.*.bossSource', [Survey::BOSS_SOURCE_REPORT, Survey::BOSS_SOURCE_HISTORY, null]);
    }

    public function test_amazon_trends_use_only_surveys_and_leave_days_without_a_survey_empty(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create(['product_code' => 'A-1001']);
        Sku::factory()->for($product)->create(['sku_code' => 'A-1001-M', 'sort_order' => 1]);
        $this->snapshot('2026-09-27 10:00:00', 'A-1001', 'A-1001-M', amazonOwn: 11, bossOwn: 20);
        $this->snapshot('2026-09-28 10:00:00', 'A-1001', 'A-1001-M', amazonOwn: 12, bossOwn: 30);
        $this->snapshot('2026-09-30 10:00:00', 'A-1001', 'A-1001-M', amazonOwn: 13, bossOwn: 40);
        $this->apiSnapshot('2026-09-27 09:00:00', 'A-1001', 'A-1001-M', 1, 1);
        $this->apiSnapshot('2026-09-28 18:00:00', 'A-1001', 'A-1001-M', 2, 3);
        $this->apiSnapshot('2026-09-29 19:00:00', 'A-1001', 'A-1001-M', 6, 7, completedAt: '2026-09-29 20:00:00');
        $this->apiSnapshot('2026-09-29 18:00:00', 'A-1001', 'A-1001-M', 50, 60, completedAt: '2026-09-29 21:00:00');
        $this->apiSnapshot('2026-09-30 20:00:00', 'A-1001', 'A-1001-M', 90, 90, 'failed');
        Survey::query()->update(['amazon_source' => Survey::AMAZON_SOURCE_LEGACY_FILE, 'includes_amazon_inventory' => true]);

        $amazon = $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-30&scope=amazon')
            ->assertOk()
            ->assertJsonPath('data.series.0.points.0.quantity', 12)
            ->assertJsonPath('data.series.0.points.1.quantity', 13)
            ->assertJsonPath('data.series.0.points.2.quantity', null)
            ->assertJsonPath('data.series.0.points.2.source', null)
            ->assertJsonPath('data.series.0.points.3.quantity', 14)
            ->assertJsonPath('data.dailySources.*.date', ['2026-09-27', '2026-09-28', '2026-09-30'])
            ->assertJsonPath('data.dailySources.*.source', ['survey', 'survey', 'survey'])
            ->assertJsonPath('data.dailySources.0.amazonInventoryStatus', 'legacy_file');
        $this->assertNotEmpty($amazon->json('data.dailySources.1.basisAt'));

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-30&scope=grandTotal')
            ->assertOk()
            ->assertJsonPath('data.series.0.points.1.quantity', 46)
            ->assertJsonPath('data.series.0.points.2.quantity', null);
        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-30&scope=boss')
            ->assertOk()->assertJsonPath('data.series.0.points.1.quantity', 31);

        $contents = file_get_contents($this->get('/api/inventory-trends/export?product_code=A-1001&from=2026-09-28&to=2026-09-28')->baseResponse->getFile()->getPathname());
        $this->assertIsString($contents);
        $this->assertStringContainsString('A-1001-M,', $contents);
        $this->assertStringContainsString(',12,1,30,1,1,1', $contents);
    }

    public function test_pending_three_file_survey_takes_precedence_over_import_history_without_partial_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create(['product_code' => 'A-1001']);
        Sku::factory()->for($product)->create(['sku_code' => 'A-1001-M', 'sort_order' => 1]);
        $this->snapshot('2026-09-27 18:00:00', 'A-1001', 'A-1001-M', amazonOwn: 0, amazonFba: 0, bossOwn: 7, bossRfc: 2);
        Survey::query()->update(['includes_amazon_inventory' => false, 'amazon_source' => Survey::AMAZON_SOURCE_SP_API]);
        SurveySku::query()->update(['amazon_own_stock' => null, 'amazon_fba_stock' => null]);
        $this->apiSnapshot('2026-09-27 09:00:00', 'A-1001', 'A-1001-M', 4, 6);

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-27&scope=amazon')
            ->assertOk()
            ->assertJsonPath('data.series.0.points.0.quantity', null)
            ->assertJsonPath('data.series.0.points.0.source', 'survey')
            ->assertJsonPath('data.dailySources.0.amazonBasisAt', null)
            ->assertJsonPath('data.dailySources.0.amazonInventoryStatus', 'pending');
        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-27&scope=boss')
            ->assertOk()->assertJsonPath('data.series.0.points.0.quantity', 9);
        foreach (['mallTotal', 'grandTotal'] as $scope) {
            $this->getJson("/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-27&scope={$scope}")
                ->assertOk()->assertJsonPath('data.series.0.points.0.quantity', null);
        }

        $rows = iterator_to_array(app(InventoryTrendCsvExporter::class)->rows('A-1001', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27')), false);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['amazonOwn']);
        $this->assertNull($rows[0]['amazonFba']);
        $this->assertSame(7, $rows[0]['bossOwn']);
    }

    public function test_latest_same_day_three_file_survey_supplies_saved_values_for_every_trend_scope(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['product_code' => 'A-1001']);
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'snapshot_date' => '2026-09-27',
            'basis_at' => '2026-09-27 20:00:00',
            'completed_at' => '2026-09-27 20:05:00',
        ]);
        $import->snapshots()->create([
            'product_code' => 'A-1001',
            'sku_code' => 'A-1001-M',
            'asin' => 'B000000001',
            'amazon_own_stock' => 99,
            'amazon_fba_stock' => 99,
            'basis_at' => $import->basis_at,
        ]);
        $this->snapshot('2026-09-27 08:00:00', 'A-1001', 'A-1001-M', amazonOwn: 1, amazonFba: 2);
        $survey = Survey::factory()->spApi()->create([
            'executed_at' => '2026-09-27 18:00:00',
            'amazon_inventory_import_id' => $import->id,
        ]);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001', 'sort_order' => 1]);
        foreach ([['A-1001-M', 4, 5], ['A-1001-L', null, null]] as $index => [$code, $own, $fba]) {
            SurveySku::factory()->for($product, 'product')->create([
                'sku_code' => $code,
                'child_asin' => 'B000000001',
                'sort_order' => $index + 1,
                'amazon_own_stock' => $own,
                'amazon_fba_stock' => $fba,
                'boss_own_stock' => 4,
                'boss_rfc_stock' => 5,
                'free_stock' => 6,
                'ec_stock' => 7,
            ]);
        }

        foreach (['amazon' => 9, 'boss' => 9, 'mallTotal' => 18, 'grandTotal' => 31] as $scope => $expected) {
            $this->getJson("/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-27&scope={$scope}")
                ->assertOk()
                ->assertJsonPath('data.series.0.points.0.quantity', $expected)
                ->assertJsonPath('data.series.1.points.0.quantity', $scope === 'boss' ? 9 : null)
                ->assertJsonPath('data.dailySources.0.source', 'survey')
                ->assertJsonPath('data.dailySources.0.amazonInventoryStatus', 'partial')
                ->assertJsonPath('data.dailySources.0.amazonBasisAt', $import->basis_at->toIso8601String());
        }

        $rows = iterator_to_array(app(InventoryTrendCsvExporter::class)->rows('A-1001', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27')), false);
        $this->assertCount(2, $rows);
        $this->assertSame(4, $rows[0]['amazonOwn']);
        $this->assertNull($rows[1]['amazonOwn']);
        $this->assertNull($rows[1]['amazonFba']);
    }

    public function test_asinless_sku_does_not_make_amazon_or_overall_trends_partial(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['product_code' => 'A-1001']);
        $survey = Survey::factory()->spApi()->create(['executed_at' => '2026-09-27 10:00:00']);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001']);
        SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => 'A-1001-M',
            'child_asin' => null,
            'amazon_own_stock' => null,
            'amazon_fba_stock' => null,
            'boss_own_stock' => 4,
            'boss_rfc_stock' => 5,
            'free_stock' => 6,
            'ec_stock' => 7,
        ]);

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-27&scope=amazon')
            ->assertOk()
            ->assertJsonCount(0, 'data.series');

        foreach (['mallTotal' => 9, 'grandTotal' => 22] as $scope => $expected) {
            $this->getJson("/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-27&scope={$scope}")
                ->assertOk()
                ->assertJsonPath('data.series.0.points.0.quantity', $expected);
        }
    }

    public function test_warehouse_totals_sum_every_sku_per_day_and_match_the_grand_total(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['product_code' => 'A-1001']);
        $survey = Survey::factory()->spApi()->create(['executed_at' => '2026-09-27 10:00:00']);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001']);
        foreach ([['A-1001-M', 'B000000001', 1, 2], ['A-1001-L', null, null, null]] as $index => [$code, $asin, $own, $fba]) {
            SurveySku::factory()->for($product, 'product')->create([
                'sku_code' => $code,
                'child_asin' => $asin,
                'sort_order' => $index + 1,
                'amazon_own_stock' => $own,
                'amazon_fba_stock' => $fba,
                'boss_own_stock' => 3,
                'boss_rfc_stock' => 4,
                'free_stock' => 5,
                'ec_stock' => 6,
            ]);
        }

        $response = $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-26&to=2026-09-27&scope=grandTotal')
            ->assertOk()
            ->assertJsonPath('data.warehouseTotals.0', ['date' => '2026-09-26', 'amazon' => null, 'boss' => null, 'free' => null, 'stock' => null])
            ->assertJsonPath('data.warehouseTotals.1', ['date' => '2026-09-27', 'amazon' => 3, 'boss' => 14, 'free' => 10, 'stock' => 12]);

        $grandTotal = collect($response->json('data.series'))->sum(fn (array $series): int => $series['points'][1]['quantity']);
        $this->assertSame(3 + 14 + 10 + 12, $grandTotal);
    }

    public function test_warehouse_totals_leave_a_pending_warehouse_unconfirmed_instead_of_a_partial_sum(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['product_code' => 'A-1001']);
        $survey = Survey::factory()->spApi()->create(['executed_at' => '2026-09-27 10:00:00']);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001']);
        foreach ([['A-1001-M', 1, 2, 3], ['A-1001-L', null, 4, null]] as $index => [$code, $own, $bossOwn, $free]) {
            SurveySku::factory()->for($product, 'product')->create([
                'sku_code' => $code,
                'child_asin' => 'B00000000'.$index,
                'sort_order' => $index + 1,
                'amazon_own_stock' => $own,
                'amazon_fba_stock' => $own === null ? null : 0,
                'boss_own_stock' => $bossOwn,
                'boss_rfc_stock' => 0,
                'free_stock' => $free,
                'ec_stock' => null,
            ]);
        }

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-09-27&to=2026-09-27&scope=grandTotal')
            ->assertOk()
            ->assertJsonPath('data.warehouseTotals.0', ['date' => '2026-09-27', 'amazon' => null, 'boss' => 6, 'free' => null, 'stock' => null]);
    }

    public function test_amazon_only_survey_keeps_other_warehouses_pending_in_trends_and_daily_csv(): void
    {
        $this->actingAs(User::factory()->create());
        Product::factory()->create(['product_code' => 'A-1001']);
        $survey = Survey::factory()->spApi()->create(['executed_at' => '2026-10-19 09:05:00', 'survey_date' => '2026-10-19']);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001']);
        SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => 'A-1001-M',
            'child_asin' => 'B000000001',
            'amazon_own_stock' => 4,
            'amazon_fba_stock' => 6,
            'boss_own_stock' => null,
            'boss_rfc_stock' => null,
            'free_stock' => null,
            'ec_stock' => null,
        ]);

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2026-10-19&to=2026-10-19&scope=amazon')
            ->assertOk()->assertJsonPath('data.series.0.points.0.quantity', 10);
        foreach (['boss', 'mallTotal', 'grandTotal'] as $scope) {
            $this->getJson("/api/inventory-trends?product_code=A-1001&from=2026-10-19&to=2026-10-19&scope={$scope}")
                ->assertOk()
                ->assertJsonPath('data.series.0.points.0.quantity', null)
                ->assertJsonPath('data.warehouseTotals.0', ['date' => '2026-10-19', 'amazon' => 10, 'boss' => null, 'free' => null, 'stock' => null]);
        }

        $rows = iterator_to_array(app(InventoryTrendCsvExporter::class)->rows('A-1001', CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19')), false);
        $this->assertCount(1, $rows);
        $this->assertSame([4, 6, null, null, null, null], [$rows[0]['amazonOwn'], $rows[0]['amazonFba'], $rows[0]['bossOwn'], $rows[0]['bossRfc'], $rows[0]['freeStock'], $rows[0]['ecStock']]);
    }

    public function test_daily_inventory_can_be_exported_as_csv_by_sku_and_warehouse(): void
    {
        $this->actingAs(User::factory()->create());
        $product = Product::factory()->create(['product_code' => 'A-1001']);
        Sku::factory()->for($product)->create(['sku_code' => 'A-1001-M', 'sort_order' => 1]);

        $this->snapshot('2026-09-27 09:00:00', 'A-1001', 'A-1001-M', amazonOwn: 1, amazonFba: 2, bossOwn: 3, bossRfc: 4, free: 5, stock: 6);
        $this->snapshot('2026-09-27 18:00:00', 'A-1001', 'A-1001-M', amazonOwn: 10, amazonFba: 20, bossOwn: 30, bossRfc: 40, free: 50, stock: 60);
        $this->snapshot('2026-09-29 10:00:00', 'A-1001', 'A-1001-M', amazonOwn: 11, amazonFba: 21, bossOwn: 31, bossRfc: 41, free: 51, stock: 61);

        $response = $this->get('/api/inventory-trends/export?product_code=A-1001&from=2026-09-27&to=2026-09-29');

        $response->assertOk()
            ->assertDownload('A-1001_2026-09-27_2026-09-29_daily_inventory.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $contents = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertIsString($contents);
        $this->assertStringContainsString("\r\n", $contents);
        $rows = array_map(
            fn (string $line): array => str_getcsv($line, ',', '"', ''),
            explode("\r\n", trim($contents)),
        );

        $this->assertSame([
            'date', 'productCode', 'brand', 'category', 'sku', 'size',
            'amazonOwn', 'amazonFba', 'bossOwn', 'bossRfc', 'freeStock', 'ecStock',
        ], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['2026-09-27', 'A-1001', 'ALPHA', 'トップス', 'A-1001-M'], array_slice($rows[1], 0, 5));
        $this->assertSame(['10', '20', '30', '40', '50', '60'], array_slice($rows[1], 6));
        $this->assertSame(['11', '21', '31', '41', '51', '61'], array_slice($rows[2], 6));
    }

    public function test_daily_inventory_for_all_products_uses_each_products_latest_daily_snapshot(): void
    {
        $this->actingAs(User::factory()->create());

        $this->snapshot('2026-09-27 09:00:00', 'A-1001', 'A-1001-M', amazonOwn: 1, amazonFba: 2, bossOwn: 3, bossRfc: 4, free: 5, stock: 6);
        $this->snapshot('2026-09-27 10:00:00', 'B-2002', 'B-2002-L', amazonOwn: 70, amazonFba: 71, bossOwn: 72, bossRfc: 73, free: 74, stock: 75);
        $this->snapshot('2026-09-27 18:00:00', 'A-1001', 'A-1001-M', amazonOwn: 90, amazonFba: 91, bossOwn: 92, bossRfc: 93, free: 94, stock: 95);

        $response = $this->get('/api/inventory-trends/export?from=2026-09-27&to=2026-09-27');

        $response->assertOk()
            ->assertDownload('all_products_2026-09-27_2026-09-27_daily_inventory.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $contents = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertIsString($contents);
        $rows = array_map(
            fn (string $line): array => str_getcsv($line, ',', '"', ''),
            explode("\r\n", trim($contents)),
        );

        $this->assertCount(3, $rows);
        $this->assertSame('A-1001', $rows[1][1]);
        $this->assertSame(['90', '91', '92', '93', '94', '95'], array_slice($rows[1], 6));
        $this->assertSame('B-2002', $rows[2][1]);
        $this->assertSame(['70', '71', '72', '73', '74', '75'], array_slice($rows[2], 6));
    }

    public function test_daily_inventory_export_validates_the_period(): void
    {
        $this->actingAs(User::factory()->create());
        $this->snapshot('2026-09-27 10:00:00', 'A-1001', 'A-1001-M');

        $this->getJson('/api/inventory-trends/export?product_code=A-1001&from=2026-09-28&to=2026-09-27')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/inventory-trends/export?product_code=A-1001&from=2025-08-01&to=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/inventory-trends?product_code=A-1001&from=2025-08-01&to=2026-08-01&scope=amazon')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    private function snapshot(
        string $executedAt,
        string $productCode,
        string $skuCode,
        int $amazonOwn = 1,
        int $amazonFba = 1,
        int $bossOwn = 1,
        int $bossRfc = 1,
        int $free = 1,
        int $stock = 1,
        ?string $createdAt = null,
    ): void {
        $surveyAttributes = ['executed_at' => $executedAt];
        if ($createdAt !== null) {
            $surveyAttributes['created_at'] = $createdAt;
            $surveyAttributes['updated_at'] = $createdAt;
        }
        $survey = Survey::factory()->create($surveyAttributes);
        $surveyProduct = SurveyProduct::factory()->for($survey)->create([
            'product_code' => $productCode,
            'brand' => 'ALPHA',
            'category' => 'トップス',
            'sort_order' => 1,
        ]);
        SurveySku::factory()->for($surveyProduct, 'product')->create([
            'sku_code' => $skuCode,
            'child_asin' => 'B000000001',
            'sort_order' => 1,
            'amazon_own_stock' => $amazonOwn,
            'amazon_fba_stock' => $amazonFba,
            'boss_own_stock' => $bossOwn,
            'boss_rfc_stock' => $bossRfc,
            'free_stock' => $free,
            'ec_stock' => $stock,
        ]);
    }

    private function apiSnapshot(
        string $basisAt,
        string $productCode,
        string $skuCode,
        int $own,
        int $fba,
        string $status = 'succeeded',
        ?string $completedAt = null,
    ): void {
        $import = AmazonInventoryImport::query()->create([
            'status' => $status,
            'trigger_source' => 'manual',
            'basis_at' => $basisAt,
            'snapshot_date' => substr($basisAt, 0, 10),
            'completed_at' => $completedAt ?? $basisAt,
        ]);
        $import->snapshots()->create([
            'product_code' => $productCode,
            'sku_code' => $skuCode,
            'asin' => 'B012345678',
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
            'basis_at' => $basisAt,
        ]);
    }
}
