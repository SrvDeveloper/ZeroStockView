<?php

namespace Tests\Feature;

use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_domain_schema_is_available(): void
    {
        $expectedColumns = [
            'users' => ['id', 'login_id', 'password'],
            'products' => ['id', 'product_code', 'brand', 'category', 'parent_asin', 'status', 'source_updated_at', 'synced_at'],
            'skus' => ['id', 'product_id', 'sku_code', 'child_asin', 'status', 'tq_item_no', 'tq_color_no', 'tq_size', 'sort_order'],
            'import_settings' => ['id', 'name'],
            'import_targets' => ['id', 'import_setting_id', 'product_id', 'sort_order'],
            'surveys' => ['id', 'executed_at', 'import_setting_name'],
            'survey_products' => ['id', 'survey_id', 'product_code', 'brand', 'category', 'parent_asin', 'status', 'source_updated_at', 'sort_order', 'memo'],
            'survey_skus' => [
                'id',
                'survey_product_id',
                'sku_code',
                'child_asin',
                'status',
                'tq_item_no',
                'tq_color_no',
                'tq_size',
                'sort_order',
                'amazon_own_stock',
                'amazon_fba_stock',
                'boss_own_stock',
                'boss_rfc_stock',
                'free_stock',
                'ec_stock',
                'memo',
            ],
            'survey_files' => ['id', 'survey_id', 'file_type', 'original_name', 'disk', 'stored_path', 'mime_type', 'size_bytes', 'uploaded_at', 'expires_at'],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertTrue(Schema::hasColumns($table, $columns));
        }
    }

    public function test_current_product_data_and_survey_snapshots_have_independent_lifecycles(): void
    {
        $product = Product::factory()->create(['product_code' => 'A-1001']);
        $sku = Sku::factory()->for($product)->create(['sku_code' => 'A-1001-01-M']);
        $setting = ImportSetting::query()->create(['name' => '売上TOP20']);
        $target = ImportTarget::factory()->for($setting, 'setting')->for($product)->create(['sort_order' => 1]);

        $survey = Survey::factory()->create();
        $surveyProduct = SurveyProduct::factory()->for($survey)->create([
            'product_code' => $product->product_code,
            'sort_order' => 1,
        ]);
        $surveySku = SurveySku::factory()->for($surveyProduct, 'product')->create([
            'sku_code' => $sku->sku_code,
            'sort_order' => 1,
            'amazon_own_stock' => 12,
        ]);
        $surveyFile = SurveyFile::factory()->for($survey)->create([
            'file_type' => '在庫商品レポート',
        ]);

        $product->delete();

        $this->assertModelMissing($sku);
        $this->assertModelMissing($target);
        $this->assertModelExists($surveyProduct);
        $this->assertModelExists($surveySku);
        $this->assertSame(12, $surveySku->fresh()->amazon_own_stock);

        $survey->delete();

        $this->assertModelMissing($surveyProduct);
        $this->assertModelMissing($surveySku);
        $this->assertModelMissing($surveyFile);
    }

    public function test_file_retention_dates_are_cast_as_datetimes(): void
    {
        $file = SurveyFile::factory()->create();

        $this->assertSame(30, (int) $file->uploaded_at->diffInDays($file->expires_at));
    }
}
