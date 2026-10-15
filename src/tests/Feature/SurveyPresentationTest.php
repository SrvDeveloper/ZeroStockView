<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Models\User;
use App\Services\SurveyExcelExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class SurveyPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_result_history_and_excel_use_saved_values_for_partially_imported_survey(): void
    {
        $this->actingAs(User::factory()->create());
        $import = $this->import();
        $survey = Survey::factory()->spApi()->create([
            'executed_at' => '2026-10-15 08:00:00',
            'amazon_inventory_import_id' => $import->id,
        ]);
        $product = SurveyProduct::factory()->for($survey)->create();
        $matched = $this->sku($product, 'SKU-MATCHED', 2, 3);
        $missing = $this->sku($product, 'SKU-MISSING', null, null);

        $this->getJson('/api/surveys/'.$survey->id)
            ->assertOk()
            ->assertJsonPath('data.amazonInventoryStatus', 'partial')
            ->assertJsonPath('data.amazonInventoryImportId', (string) $import->id)
            ->assertJsonPath('data.amazonSource', 'sp_api')
            ->assertJsonPath('data.amazonBasisAt', $import->basis_at->toISOString())
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 2)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', 3)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonTotal', 5)
            ->assertJsonPath('data.products.0.skus.0.stock.mallTotal', 14)
            ->assertJsonPath('data.products.0.skus.0.stock.grandTotal', 27)
            ->assertJsonPath('data.products.0.skus.1.stock.amazonOwn', null)
            ->assertJsonPath('data.products.0.skus.1.stock.amazonTotal', null)
            ->assertJsonPath('data.products.0.skus.1.stock.mallTotal', null)
            ->assertJsonPath('data.products.0.skus.1.stock.grandTotal', null);
        $this->getJson('/api/surveys')->assertOk()
            ->assertJsonPath('data.0.amazonInventoryStatus', 'partial')
            ->assertJsonPath('data.0.products.0.skus.0.stock.amazonOwn', 2);

        $sheet = $this->sheet($survey);
        $this->assertStringContainsString('Amazon:自社', $sheet);
        $this->assertStringContainsString('<c r="J2"><v>2</v></c>', $sheet);
        $this->assertStringContainsString('<c r="K2"><v>3</v></c>', $sheet);
        $this->assertStringContainsString('<c r="J3" t="inlineStr"><is><t xml:space="preserve"></t></is></c>', $sheet);
        $this->assertStringNotContainsString('<v>99</v>', $sheet);

        $missing->update(['amazon_own_stock' => 0, 'amazon_fba_stock' => 0]);
        $this->getJson('/api/surveys/'.$survey->id)
            ->assertOk()
            ->assertJsonPath('data.amazonInventoryStatus', 'complete')
            ->assertJsonPath('data.products.0.skus.1.stock.amazonTotal', 0)
            ->assertJsonPath('data.products.0.skus.1.stock.mallTotal', 9);
        $completeSheet = $this->sheet($survey->fresh());
        $this->assertStringContainsString('<c r="J3"><v>0</v></c>', $completeSheet);
        $this->assertStringContainsString('<c r="K3"><v>0</v></c>', $completeSheet);
        $this->assertSame(2, $matched->fresh()->amazon_own_stock);
    }

    public function test_pending_survey_shows_blank_amazon_cells_even_when_a_snapshot_exists(): void
    {
        $this->actingAs(User::factory()->create());
        $this->import();
        $survey = Survey::factory()->spApi()->create(['executed_at' => '2026-10-15 08:00:00']);
        $product = SurveyProduct::factory()->for($survey)->create();
        $this->sku($product, 'SKU-MATCHED', null, null);

        $this->getJson('/api/surveys/'.$survey->id)
            ->assertOk()
            ->assertJsonPath('data.amazonInventoryStatus', 'pending')
            ->assertJsonPath('data.amazonSource', 'sp_api')
            ->assertJsonPath('data.amazonBasisAt', null)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', null)
            ->assertJsonPath('data.products.0.skus.0.stock.grandTotal', null);

        $sheet = $this->sheet($survey);
        $this->assertStringContainsString('Amazon:自社', $sheet);
        $this->assertStringContainsString('<c r="J2" t="inlineStr"><is><t xml:space="preserve"></t></is></c>', $sheet);
        $this->assertStringContainsString('<c r="K2" t="inlineStr"><is><t xml:space="preserve"></t></is></c>', $sheet);
        $this->assertStringNotContainsString('<v>99</v>', $sheet);
    }

    public function test_sku_without_asin_is_outside_amazon_scope_and_keeps_other_stock_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $import = $this->import();
        $survey = Survey::factory()->spApi()->create(['amazon_inventory_import_id' => $import->id]);
        $product = SurveyProduct::factory()->for($survey)->create();
        $this->sku($product, 'SKU-MATCHED', 2, 3);
        $this->sku($product, 'SKU-NO-ASIN', null, null)->update(['child_asin' => null]);

        $this->getJson('/api/surveys/'.$survey->id)
            ->assertOk()
            ->assertJsonPath('data.amazonInventoryStatus', 'complete')
            ->assertJsonPath('data.products.0.skus.1.stock.amazonTotal', null)
            ->assertJsonPath('data.products.0.skus.1.stock.mallTotal', 9)
            ->assertJsonPath('data.products.0.skus.1.stock.grandTotal', 22);
    }

    public function test_legacy_survey_retains_its_saved_amazon_values_and_source(): void
    {
        $this->actingAs(User::factory()->create());
        $import = $this->import();
        $survey = Survey::factory()->legacyFile()->create([
            'executed_at' => '2026-10-15 08:00:00',
            'amazon_inventory_import_id' => $import->id,
        ]);
        $product = SurveyProduct::factory()->for($survey)->create();
        $this->sku($product, 'SKU-MATCHED', 4, 7);

        $this->getJson('/api/surveys/'.$survey->id)
            ->assertOk()
            ->assertJsonPath('data.amazonInventoryStatus', 'legacy_file')
            ->assertJsonPath('data.amazonSource', 'survey')
            ->assertJsonPath('data.amazonInventoryImportId', null)
            ->assertJsonPath('data.amazonBasisAt', $survey->executed_at->toISOString())
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 4)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', 7)
            ->assertJsonPath('data.products.0.skus.0.stock.grandTotal', 33);

        $sheet = $this->sheet($survey);
        $this->assertStringContainsString('<c r="J2"><v>4</v></c>', $sheet);
        $this->assertStringContainsString('<c r="K2"><v>7</v></c>', $sheet);
        $this->assertStringNotContainsString('Amazon取得元', $sheet);
        $this->assertStringNotContainsString('<v>99</v>', $sheet);
    }

    private function import(): AmazonInventoryImport
    {
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'snapshot_date' => '2026-10-15',
            'basis_at' => '2026-10-15 09:00:00',
            'completed_at' => '2026-10-15 09:05:00',
        ]);
        foreach (['SKU-MATCHED', 'SKU-MISSING'] as $code) {
            $import->snapshots()->create([
                'product_code' => 'P-1',
                'sku_code' => $code,
                'asin' => 'B000000001',
                'amazon_own_stock' => 99,
                'amazon_fba_stock' => 99,
                'basis_at' => $import->basis_at,
            ]);
        }

        return $import;
    }

    private function sku(SurveyProduct $product, string $code, ?int $own, ?int $fba): SurveySku
    {
        return SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => $code,
            'child_asin' => 'B000000001',
            'sort_order' => $code === 'SKU-MATCHED' ? 1 : 2,
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
            'boss_own_stock' => 4,
            'boss_rfc_stock' => 5,
            'free_stock' => 6,
            'ec_stock' => 7,
        ]);
    }

    private function sheet(Survey $survey): string
    {
        $path = app(SurveyExcelExporter::class)->export($survey);
        $zip = new ZipArchive;

        try {
            $this->assertTrue($zip->open($path));

            return $zip->getFromName('xl/worksheets/sheet1.xml');
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
