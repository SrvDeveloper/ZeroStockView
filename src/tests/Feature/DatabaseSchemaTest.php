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
            'api_connection_settings' => ['id', 'enabled', 'api_key_hash', 'api_key_suffix', 'api_key_issued_at', 'allowed_networks'],
            'surveys' => ['id', 'executed_at', 'import_setting_name', 'amazon_source'],
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

        $this->assertTrue(Schema::hasIndex('survey_products', ['product_code']));
        $this->assertTrue(Schema::hasIndex('surveys', ['amazon_source', 'executed_at']));
    }

    public function test_pending_amazon_stock_is_null_and_legacy_quantities_remain_numeric(): void
    {
        $legacy = Survey::factory()->legacyFile()->create();
        $legacyProduct = SurveyProduct::factory()->for($legacy)->create();
        $legacySku = SurveySku::factory()->for($legacyProduct, 'product')->create([
            'amazon_own_stock' => 12,
            'amazon_fba_stock' => 3,
        ]);

        $pending = Survey::factory()->spApi()->create();
        $pendingProduct = SurveyProduct::factory()->for($pending)->create();
        $pendingSku = SurveySku::factory()->pendingAmazon()->for($pendingProduct, 'product')->create();

        $this->assertSame(Survey::AMAZON_SOURCE_LEGACY_FILE, $legacy->fresh()->amazon_source);
        $this->assertSame(12, $legacySku->fresh()->amazon_own_stock);
        $this->assertSame(3, $legacySku->fresh()->amazon_fba_stock);
        $this->assertSame(Survey::AMAZON_SOURCE_SP_API, $pending->fresh()->amazon_source);
        $this->assertNull($pendingSku->fresh()->amazon_own_stock);
        $this->assertNull($pendingSku->fresh()->amazon_fba_stock);
    }

    public function test_schema_rollback_refuses_to_erase_pending_amazon_state(): void
    {
        $pending = Survey::factory()->spApi()->create();
        $product = SurveyProduct::factory()->for($pending)->create();
        SurveySku::factory()->pendingAmazon()->for($product, 'product')->create();

        $migration = require database_path('migrations/2026_10_15_000000_allow_pending_amazon_stock_on_surveys.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_schema_rollback_refuses_to_erase_explicit_amazon_source(): void
    {
        Survey::factory()->spApi()->create();
        $migration = require database_path('migrations/2026_10_15_000000_allow_pending_amazon_stock_on_surveys.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
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
