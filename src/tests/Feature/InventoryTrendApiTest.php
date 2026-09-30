<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sku;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Models\User;
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
    ): void {
        $survey = Survey::factory()->create(['executed_at' => $executedAt]);
        $surveyProduct = SurveyProduct::factory()->for($survey)->create([
            'product_code' => $productCode,
            'brand' => 'ALPHA',
            'category' => 'トップス',
            'sort_order' => 1,
        ]);
        SurveySku::factory()->for($surveyProduct, 'product')->create([
            'sku_code' => $skuCode,
            'sort_order' => 1,
            'amazon_own_stock' => $amazonOwn,
            'amazon_fba_stock' => $amazonFba,
            'boss_own_stock' => $bossOwn,
            'boss_rfc_stock' => $bossRfc,
            'free_stock' => $free,
            'ec_stock' => $stock,
        ]);
    }
}
