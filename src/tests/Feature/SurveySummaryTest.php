<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Models\User;
use App\Services\SurveySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 調査履歴の一覧の集計値。全倉庫ゼロ・一部ゼロの規則は画面の utils/stock.ts（stock.test.ts）と同じにする。
 */
class SurveySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sp_api_survey_counts_asin_less_skus_as_outside_amazon_and_skips_pending_ones(): void
    {
        $survey = Survey::factory()->spApi()->create(['amazon_inventory_import_id' => $this->import()->id]);
        $product = SurveyProduct::factory()->for($survey)->create();
        $this->sku($product, 'B1', [5, 1, 3, 2, 6, 4]);          // 在庫あり
        $this->sku($product, 'B2', [0, 0, 0, 0, 0, 0]);          // 全倉庫ゼロ
        $this->sku($product, null, [null, null, 0, 0, 0, 0]);    // ASINなし: Amazonは対象外として全倉庫ゼロ
        $this->sku($product, 'B4', [null, null, 0, 0, 0, 0]);    // ASINあり・Amazon未取得: 未確定で数えない
        $this->sku($product, 'B5', [0, 0, 3, 0, 1, 1]);          // Amazonが0: 一部ゼロ
        $this->sku($product, '  ', [null, null, 3, 0, 0, 2]);    // ASINなし・フリーが0: 一部ゼロ
        $this->sku($product, null, [null, null, 3, 0, 1, 2]);    // ASINなし・ほかに0がない: Amazonを0の倉庫として数えない

        $summary = $this->summary($survey);

        $this->assertSame(1, $summary['productCount']);
        $this->assertSame(7, $summary['skuCount']);
        $this->assertSame(2, $summary['allZeroCount']);
        $this->assertSame(2, $summary['partialZeroCount']);
        $this->assertFalse($summary['hasSavedAmazonInventory']);
        $this->assertSame('partial', $summary['amazonInventoryStatus']);
        $this->assertSame($survey->load('products.skus')->amazonInventoryStatus(), $summary['amazonInventoryStatus']);
    }

    public function test_sp_api_survey_without_an_import_is_pending_and_complete_when_every_asin_has_stock(): void
    {
        $pending = Survey::factory()->spApi()->create();
        $this->sku(SurveyProduct::factory()->for($pending)->create(), 'B1', [null, null, 1, 1, 1, 1]);
        $complete = Survey::factory()->spApi()->create(['amazon_inventory_import_id' => $this->import()->id]);
        $product = SurveyProduct::factory()->for($complete)->create();
        $this->sku($product, 'B1', [1, 0, 1, 1, 1, 1]);
        $this->sku($product, null, [null, null, 1, 1, 1, 1]);

        $this->assertSame('pending', $this->summary($pending)['amazonInventoryStatus']);
        $this->assertSame('complete', $this->summary($complete)['amazonInventoryStatus']);
        $this->assertSame($complete->load('products.skus')->amazonInventoryStatus(), 'complete');
    }

    public function test_legacy_survey_uses_its_saved_amazon_stock_and_keeps_missing_values_pending(): void
    {
        $survey = Survey::factory()->legacyFile()->create();
        $product = SurveyProduct::factory()->for($survey)->create();
        $this->sku($product, null, [null, null, 0, 0, 0, 0]);    // Amazonが未確定: 数えない
        $this->sku($product, null, [0, 0, 0, 0, 0, 0]);          // 全倉庫ゼロ
        $this->sku($product, 'B3', [0, 0, 5, 0, 1, 1]);          // Amazonが0: 一部ゼロ

        $summary = $this->summary($survey);

        $this->assertSame(1, $summary['allZeroCount']);
        $this->assertSame(1, $summary['partialZeroCount']);
        $this->assertTrue($summary['hasSavedAmazonInventory']);
        $this->assertSame('legacy_file', $summary['amazonInventoryStatus']);
    }

    public function test_unclassified_survey_ignores_amazon_unless_some_amazon_stock_was_saved(): void
    {
        $withoutAmazon = Survey::factory()->create(['amazon_source' => null, 'includes_amazon_inventory' => false]);
        $product = SurveyProduct::factory()->for($withoutAmazon)->create();
        $this->sku($product, 'B1', [null, null, 0, 0, 0, 0]);    // Amazonを数えないので全倉庫ゼロ
        $this->sku($product, 'B2', [null, null, 3, 0, 0, 1]);    // 一部ゼロ
        $this->sku($product, 'B3', [null, null, null, 0, 0, 1]); // BOSSが未確定: 数えない

        $withAmazon = Survey::factory()->create(['amazon_source' => null, 'includes_amazon_inventory' => false]);
        $this->sku(SurveyProduct::factory()->for($withAmazon)->create(), 'B1', [2, 0, 0, 0, 0, 0]);

        $summary = $this->summary($withoutAmazon);
        $this->assertSame([1, 1], [$summary['allZeroCount'], $summary['partialZeroCount']]);
        $this->assertFalse($summary['hasSavedAmazonInventory']);
        $this->assertSame('unclassified', $summary['amazonInventoryStatus']);

        $saved = $this->summary($withAmazon);
        $this->assertTrue($saved['hasSavedAmazonInventory']);
        $this->assertSame([0, 1], [$saved['allZeroCount'], $saved['partialZeroCount']]);
        $this->assertSame('legacy_file', $saved['amazonInventoryStatus']);
    }

    public function test_product_count_includes_products_without_skus(): void
    {
        $survey = Survey::factory()->spApi()->create();
        SurveyProduct::factory()->for($survey)->count(2)->create();

        $summary = $this->summary($survey);

        $this->assertSame([2, 0, 0, 0], [$summary['productCount'], $summary['skuCount'], $summary['allZeroCount'], $summary['partialZeroCount']]);
    }

    public function test_survey_list_returns_summaries_without_reading_sku_details(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $survey = Survey::factory()->spApi()->create(['amazon_inventory_import_id' => $this->import()->id]);
        $product = SurveyProduct::factory()->for($survey)->create();
        $this->sku($product, 'B1', [0, 0, 0, 0, 0, 0]);
        $this->sku($product, 'B2', [1, 1, 1, 1, 1, 1])->update(['memo' => '確認中']);
        SurveyFile::factory()->for($survey)->create(['file_type' => 'KEEP一覧表']);

        DB::enableQueryLog();
        $this->getJson('/api/surveys')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $survey->id)
            ->assertJsonPath('data.0.productCount', 1)
            ->assertJsonPath('data.0.skuCount', 2)
            ->assertJsonPath('data.0.allZeroCount', 1)
            ->assertJsonPath('data.0.partialZeroCount', 0)
            ->assertJsonPath('data.0.amazonInventoryStatus', 'complete')
            ->assertJsonPath('data.0.amazonSource', 'sp_api')
            ->assertJsonPath('data.0.files.0.type', 'KEEP一覧表')
            ->assertJsonMissingPath('data.0.products')
            ->assertJsonMissingPath('data.0.groups');

        // SKUの行を取り出す（select * from survey_skus）クエリは発行しない。集計クエリだけ
        $skuReads = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'survey_skus'));
        $this->assertCount(1, $skuReads);
        $this->assertStringContainsString('group by', strtolower($skuReads->first()));
    }

    /** @return array{productCount: int, skuCount: int, allZeroCount: int, partialZeroCount: int, hasSavedAmazonInventory: bool, amazonInventoryStatus: string} */
    private function summary(Survey $survey): array
    {
        return app(SurveySummaryService::class)->summarize(collect([$survey->fresh()]))[$survey->id];
    }

    /** @param  array{0: int|null, 1: int|null, 2: int|null, 3: int|null, 4: int|null, 5: int|null}  $stock  Amazon自社・FBA・BOSS自社・RFC・フリー・ECストック */
    private function sku(SurveyProduct $product, ?string $asin, array $stock): SurveySku
    {
        return SurveySku::factory()->for($product, 'product')->create([
            'child_asin' => $asin,
            'amazon_own_stock' => $stock[0],
            'amazon_fba_stock' => $stock[1],
            'boss_own_stock' => $stock[2],
            'boss_rfc_stock' => $stock[3],
            'free_stock' => $stock[4],
            'ec_stock' => $stock[5],
        ]);
    }

    private function import(): AmazonInventoryImport
    {
        return AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'snapshot_date' => '2026-10-15',
            'basis_at' => '2026-10-15 09:00:00',
            'completed_at' => '2026-10-15 09:05:00',
        ]);
    }
}
