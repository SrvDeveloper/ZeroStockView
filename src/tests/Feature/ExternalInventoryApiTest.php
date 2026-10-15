<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\ApiConnectionSetting;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Models\User;
use App\Services\InventoryTrendCsvExporter;
use App\Services\SurveyAmazonInventorySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'zsv_test_api_key_1234567890';

    public function test_api_connection_settings_require_a_logged_in_user(): void
    {
        $this->getJson('/api/api-connection-settings')->assertUnauthorized();
        $this->putJson('/api/api-connection-settings', [
            'enabled' => false,
            'allowed_networks' => [],
        ])->assertUnauthorized();
        $this->postJson('/api/api-connection-settings/rotate-key')->assertUnauthorized();
    }

    public function test_authenticated_user_can_rotate_a_key_and_save_enabled_network_entries(): void
    {
        $this->actingAs(User::factory()->create());

        $issued = $this->postJson('/api/api-connection-settings/rotate-key')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('apiKeyConfigured', true)
            ->assertJsonStructure(['apiKey', 'apiKeyMasked', 'apiKeyIssuedAt'])
            ->json('apiKey');

        $this->assertIsString($issued);
        $this->assertStringStartsWith('zsv_live_', $issued);

        $setting = ApiConnectionSetting::query()->sole();
        $this->assertSame(hash('sha256', $issued), $setting->api_key_hash);
        $this->assertNotSame($issued, $setting->api_key_hash);

        $this->getJson('/api/api-connection-settings')
            ->assertOk()
            ->assertJsonPath('apiKeyConfigured', true)
            ->assertJsonMissingPath('apiKey');

        $this->putJson('/api/api-connection-settings', [
            'enabled' => true,
            'allowed_networks' => [
                ['network' => '203.0.113.10', 'memo' => '本社固定IP'],
                ['network' => '198.51.100.0/24', 'memo' => '倉庫'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('allowedNetworks.0.network', '203.0.113.10')
            ->assertJsonPath('allowedNetworks.0.memo', '本社固定IP');

        $this->assertSame(hash('sha256', $issued), $setting->fresh()->api_key_hash);
    }

    public function test_api_connection_setting_validates_enablement_and_network_entries(): void
    {
        $this->actingAs(User::factory()->create());

        $this->putJson('/api/api-connection-settings', [
            'enabled' => false,
            'allowed_networks' => [['network' => '203.0.113.0/99', 'memo' => '不正']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allowed_networks.0.network');

        $this->putJson('/api/api-connection-settings', [
            'enabled' => true,
            'allowed_networks' => [['network' => '203.0.113.0/24', 'memo' => '本社']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        $this->postJson('/api/api-connection-settings/rotate-key')->assertOk();

        $this->putJson('/api/api-connection-settings', [
            'enabled' => true,
            'allowed_networks' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allowed_networks');
    }

    public function test_external_inventory_api_is_unavailable_while_disabled(): void
    {
        $this->configureApi(['203.0.113.0/24'], enabled: false);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeader('X-API-Key', self::API_KEY)
            ->getJson('/api/v1/inventory/daily?from=2026-09-27&to=2026-09-27')
            ->assertStatus(503);
    }

    public function test_external_inventory_api_requires_a_valid_key_and_allowed_source_ip(): void
    {
        $this->configureApi(['203.0.113.0/24']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->getJson('/api/v1/inventory/daily?from=2026-09-27&to=2026-09-27')
            ->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeader('X-API-Key', 'wrong-api-key-1234')
            ->getJson('/api/v1/inventory/daily?from=2026-09-27&to=2026-09-27')
            ->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->withHeader('X-API-Key', self::API_KEY)
            ->getJson('/api/v1/inventory/daily?from=2026-09-27&to=2026-09-27')
            ->assertForbidden();
    }

    public function test_external_inventory_api_returns_the_same_daily_rows_for_all_products(): void
    {
        $this->configureApi(['203.0.113.0/24']);
        $this->snapshot('2026-09-27 09:00:00', 'A-1001', 'A-1001-M', 1);
        $this->snapshot('2026-09-27 10:00:00', 'B-2002', 'B-2002-L', 70);
        $this->snapshot('2026-09-27 18:00:00', 'A-1001', 'A-1001-M', 90);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeader('X-API-Key', self::API_KEY)
            ->getJson('/api/v1/inventory/daily?from=2026-09-27&to=2026-09-27')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-09-27')
            ->assertJsonPath('data.0.productCode', 'A-1001')
            ->assertJsonPath('data.0.sku', 'A-1001-M')
            ->assertJsonPath('data.0.amazonOwn', 90)
            ->assertJsonPath('data.0.ecStock', 95)
            ->assertJsonPath('data.1.productCode', 'B-2002')
            ->assertJsonPath('data.1.amazonOwn', 70)
            ->assertJsonPath('meta.productCode', null)
            ->assertJsonPath('meta.count', 2);
    }

    public function test_external_inventory_api_can_filter_a_product_and_validates_the_period(): void
    {
        $this->configureApi(['203.0.113.42']);
        $this->snapshot('2026-09-27 10:00:00', 'A-1001', 'A-1001-M', 10);
        $this->snapshot('2026-09-27 10:00:00', 'B-2002', 'B-2002-L', 20);

        $client = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeader('X-API-Key', self::API_KEY);

        $client->getJson('/api/v1/inventory/daily?product_code=B-2002&from=2026-09-27&to=2026-09-27')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.productCode', 'B-2002')
            ->assertJsonPath('meta.productCode', 'B-2002');

        $client->getJson('/api/v1/inventory/daily?from=2026-09-28&to=2026-09-27')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $client->getJson('/api/v1/inventory/daily?from=2025-08-01&to=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_external_daily_api_and_csv_follow_saved_survey_values_before_and_after_amazon_sync(): void
    {
        $this->configureApi(['203.0.113.42']);
        $this->actingAs(User::factory()->create());
        $survey = Survey::factory()->spApi()->create(['executed_at' => '2026-09-27 08:00:00']);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001']);
        foreach (['A-1001-M', 'A-1001-L'] as $index => $code) {
            SurveySku::factory()->for($product, 'product')->pendingAmazon()->create([
                'sku_code' => $code,
                'sort_order' => $index + 1,
                'boss_own_stock' => 4,
                'boss_rfc_stock' => 5,
            ]);
        }
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'snapshot_date' => '2026-09-27',
            'basis_at' => '2026-09-27 09:00:00',
            'completed_at' => '2026-09-27 09:05:00',
        ]);
        $import->snapshots()->create([
            'product_code' => 'A-1001',
            'sku_code' => 'A-1001-M',
            'asin' => 'B000000001',
            'amazon_own_stock' => 2,
            'amazon_fba_stock' => 3,
            'basis_at' => $import->basis_at,
        ]);
        $url = '/api/v1/inventory/daily?product_code=A-1001&from=2026-09-27&to=2026-09-27';
        $client = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])->withHeader('X-API-Key', self::API_KEY);

        $client->getJson($url)->assertOk()
            ->assertJsonPath('data.0.amazonOwn', null)
            ->assertJsonPath('data.1.amazonFba', null);
        $exporter = app(InventoryTrendCsvExporter::class);
        $this->assertNull($exporter->rows('A-1001', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27'))[0]['amazonOwn']);
        $path = $exporter->export('A-1001', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27'));
        try {
            $csv = file_get_contents($path);
            $this->assertIsString($csv);
            $lines = explode("\r\n", trim($csv));
            $firstRow = str_getcsv($lines[1], ',', '"', '');
            $this->assertSame('', $firstRow[6]);
            $this->assertSame('', $firstRow[7]);
        } finally {
            unlink($path);
        }

        app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id);

        $client->getJson($url)->assertOk()
            ->assertJsonPath('data.0.amazonOwn', 2)
            ->assertJsonPath('data.0.amazonFba', 3)
            ->assertJsonPath('data.0.bossOwn', 4)
            ->assertJsonPath('data.1.amazonOwn', null)
            ->assertJsonPath('data.1.amazonFba', null);
        $rows = $exporter->rows('A-1001', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27'));
        $this->assertSame(2, $rows[0]['amazonOwn']);
        $this->assertSame(3, $rows[0]['amazonFba']);
        $this->assertNull($rows[1]['amazonOwn']);
        $this->getJson('/api/surveys/'.$survey->id)->assertOk()
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', $rows[0]['amazonOwn'])
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', $rows[0]['amazonFba']);
    }

    /** @param array<int, string> $allowedNetworks */
    private function configureApi(array $allowedNetworks, bool $enabled = true): void
    {
        ApiConnectionSetting::query()->create([
            'enabled' => $enabled,
            'api_key_hash' => hash('sha256', self::API_KEY),
            'api_key_suffix' => substr(self::API_KEY, -4),
            'api_key_issued_at' => now(),
            'allowed_networks' => array_map(fn (string $network): array => [
                'network' => $network,
                'memo' => '',
            ], $allowedNetworks),
        ]);
    }

    private function snapshot(string $executedAt, string $productCode, string $skuCode, int $base): void
    {
        $survey = Survey::factory()->create(['executed_at' => $executedAt]);
        $product = SurveyProduct::factory()->for($survey)->create([
            'product_code' => $productCode,
            'brand' => 'ALPHA',
            'category' => 'トップス',
            'sort_order' => 1,
        ]);
        SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => $skuCode,
            'tq_size' => 'M',
            'sort_order' => 1,
            'amazon_own_stock' => $base,
            'amazon_fba_stock' => $base + 1,
            'boss_own_stock' => $base + 2,
            'boss_rfc_stock' => $base + 3,
            'free_stock' => $base + 4,
            'ec_stock' => $base + 5,
        ]);
    }
}
