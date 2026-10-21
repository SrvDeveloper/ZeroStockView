<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\ApiConnectionSetting;
use App\Models\Product;
use App\Models\Sku;
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

    public function test_external_daily_api_streams_the_same_json_bytes_as_a_whole_json_response(): void
    {
        $this->configureApi(['203.0.113.0/24']);
        $this->snapshot('2026-09-26 09:00:00', 'A-1001', 'A-1001-M', 1);
        $this->snapshot('2026-09-26 18:00:00', 'A-1001', 'A-1001-M', 90);
        $this->snapshot('2026-09-26 10:00:00', 'B/2002', 'B/2002-L', 70);
        SurveyProduct::query()->where('product_code', 'B/2002')->update(['brand' => 'ブランド "B"', 'category' => 'トップス/シャツ']);
        $this->amazonSnapshot('2026-09-26', '09:00:00', 'C-3003', 'C-3003-M', 5, 6);
        $this->amazonSnapshot('2026-09-28', '09:00:00', 'A-1001', 'A-1001-M', 7, 8);
        $client = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])->withHeader('X-API-Key', self::API_KEY);

        $response = $client->get('/api/v1/inventory/daily?from=2026-09-26&to=2026-09-28')->assertOk();

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $row = fn (string $date, string $code, ?string $brand, ?string $category, string $sku, ?string $size, int $own, int $fba, ?int $base): array => [
            'date' => $date, 'productCode' => $code, 'brand' => $brand, 'category' => $category, 'sku' => $sku, 'size' => $size,
            'amazonOwn' => $own, 'amazonFba' => $fba,
            'bossOwn' => $base === null ? null : $base + 2, 'bossRfc' => $base === null ? null : $base + 3,
            'freeStock' => $base === null ? null : $base + 4, 'ecStock' => $base === null ? null : $base + 5,
        ];
        // 以前の response()->json() と同じ形・同じエスケープ（日本語は \uXXXX、/ は \/）になる
        $this->assertSame(json_encode([
            'data' => [
                $row('2026-09-26', 'A-1001', 'ALPHA', 'トップス', 'A-1001-M', 'M', 90, 91, 90),
                $row('2026-09-26', 'B/2002', 'ブランド "B"', 'トップス/シャツ', 'B/2002-L', 'M', 70, 71, 70),
                $row('2026-09-26', 'C-3003', null, null, 'C-3003-M', null, 5, 6, null),
                $row('2026-09-28', 'A-1001', null, null, 'A-1001-M', null, 7, 8, null),
            ],
            'meta' => ['from' => '2026-09-26', 'to' => '2026-09-28', 'productCode' => null, 'count' => 4],
        ]), $response->streamedContent());

        $this->assertSame(
            '{"data":[],"meta":{"from":"2026-09-27","to":"2026-09-27","productCode":"A-1001","count":0}}',
            $client->get('/api/v1/inventory/daily?product_code=A-1001&from=2026-09-27&to=2026-09-27')->assertOk()->streamedContent(),
        );
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
        $this->assertNull(iterator_to_array($exporter->rows('A-1001', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27')), false)[0]['amazonOwn']);
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
        $rows = iterator_to_array($exporter->rows('A-1001', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-27')), false);
        $this->assertSame(2, $rows[0]['amazonOwn']);
        $this->assertSame(3, $rows[0]['amazonFba']);
        $this->assertNull($rows[1]['amazonOwn']);
        $this->getJson('/api/surveys/'.$survey->id)->assertOk()
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', $rows[0]['amazonOwn'])
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', $rows[0]['amazonFba']);
    }

    public function test_external_daily_api_returns_amazon_only_rows_on_days_without_a_survey(): void
    {
        $this->configureApi(['203.0.113.42']);
        $product = Product::factory()->create([
            'product_code' => 'API-ONLY',
            'brand' => 'API BRAND',
            'category' => 'トップス',
        ]);
        Sku::factory()->for($product)->create([
            'sku_code' => 'API-ONLY-M',
            'tq_size' => 'M',
            'sort_order' => 1,
        ]);
        $this->amazonSnapshot('2026-09-27', '09:00:00', 'API-ONLY', 'API-ONLY-M', 2, 3);
        $this->amazonSnapshot('2026-09-27', '18:00:00', 'API-ONLY', 'API-ONLY-M', 0, 7);
        $this->amazonSnapshot('2026-09-27', '20:00:00', 'API-ONLY', 'API-ONLY-M', 99, 99, 'failed');
        $this->amazonSnapshot('2026-09-28', '08:00:00', 'API-ONLY', 'API-ONLY-M', 10, 11);
        $this->amazonSnapshot('2026-09-28', '09:00:00', 'OTHER', 'OTHER-M', 8, 9);
        $this->amazonSnapshot('2026-09-28', '10:00:00', 'FAILED-ONLY', 'FAILED-M', 80, 90, 'failed');

        $url = '/api/v1/inventory/daily?product_code=API-ONLY&from=2026-09-27&to=2026-09-28';
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeader('X-API-Key', self::API_KEY)
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-09-27')
            ->assertJsonPath('data.0.productCode', 'API-ONLY')
            ->assertJsonPath('data.0.brand', 'API BRAND')
            ->assertJsonPath('data.0.size', 'M')
            ->assertJsonPath('data.0.amazonOwn', 0)
            ->assertJsonPath('data.0.amazonFba', 7)
            ->assertJsonPath('data.0.bossOwn', null)
            ->assertJsonPath('data.0.bossRfc', null)
            ->assertJsonPath('data.0.freeStock', null)
            ->assertJsonPath('data.0.ecStock', null);

        $rows = iterator_to_array(app(InventoryTrendCsvExporter::class)->rows(
            'API-ONLY', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-28'),
        ), false);
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['amazonOwn']);
        $this->assertNull($rows[0]['bossOwn']);
        $path = app(InventoryTrendCsvExporter::class)->export(
            'API-ONLY', CarbonImmutable::parse('2026-09-27'), CarbonImmutable::parse('2026-09-28'),
        );
        try {
            $this->assertStringContainsString('API-ONLY-M,M,0,7,,,,', file_get_contents($path));
        } finally {
            unlink($path);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeader('X-API-Key', self::API_KEY)
            ->getJson('/api/v1/inventory/daily?product_code=FAILED-ONLY&from=2026-09-28&to=2026-09-28')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_code');
    }

    public function test_external_daily_api_keeps_survey_values_for_a_product_with_a_same_day_import(): void
    {
        $this->configureApi(['203.0.113.42']);
        $survey = Survey::factory()->create(['executed_at' => '2026-09-27 10:00:00']);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001']);
        SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => 'A-1001-M',
            'amazon_own_stock' => 4,
            'amazon_fba_stock' => 5,
        ]);
        $this->amazonSnapshot('2026-09-27', '18:00:00', 'A-1001', 'A-1001-M', 90, 91);
        $this->amazonSnapshot('2026-09-27', '19:00:00', 'OTHER', 'OTHER-M', 8, 9);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeader('X-API-Key', self::API_KEY)
            ->getJson('/api/v1/inventory/daily?from=2026-09-27&to=2026-09-27')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.productCode', 'A-1001')
            ->assertJsonPath('data.0.amazonOwn', 4)
            ->assertJsonPath('data.0.amazonFba', 5)
            ->assertJsonPath('data.1.productCode', 'OTHER')
            ->assertJsonPath('data.1.amazonOwn', 8)
            ->assertJsonPath('data.1.brand', null)
            ->assertJsonPath('data.1.size', null)
            ->assertJsonPath('data.1.bossOwn', null);
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

    private function amazonSnapshot(
        string $date,
        string $time,
        string $productCode,
        string $skuCode,
        int $own,
        int $fba,
        string $status = 'succeeded',
    ): void {
        $import = AmazonInventoryImport::query()->create([
            'status' => $status,
            'trigger_source' => 'manual',
            'snapshot_date' => $date,
            'basis_at' => "{$date} {$time}",
            'completed_at' => "{$date} {$time}",
        ]);
        $import->snapshots()->create([
            'product_code' => $productCode,
            'sku_code' => $skuCode,
            'asin' => 'B000000001',
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
            'basis_at' => $import->basis_at,
        ]);
    }
}
