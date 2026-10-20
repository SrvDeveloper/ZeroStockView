<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Models\User;
use App\Services\BulkRowWriter;
use App\Services\InventoryImportService;
use App\Services\InventoryTrendCsvExporter;
use App\Services\SurveyAmazonInventorySyncService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiBackendTest extends TestCase
{
    use RefreshDatabase;

    private int $crossWalkerResponseStatus = 200;

    /** @var array<int, array<string, mixed>> */
    private array $crossWalkerItems = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1',
            'services.crosswalker.api_key' => 'test-api-key',
        ]);

        $item = $this->crossWalkerItem();
        $this->crossWalkerItems = [$item];
        Http::fake(function (ClientRequest $request) use ($item) {
            if ($this->crossWalkerResponseStatus !== 200) {
                return Http::response(['message' => 'CrossWalker error'], $this->crossWalkerResponseStatus);
            }

            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/items/lookup')) {
                return Http::response(['data' => array_values(array_filter($this->crossWalkerItems,
                    fn (array $candidate): bool => in_array($candidate['item_no'], $request['item_nos'] ?? [], true)))]);
            }

            return Http::response([
                'data' => [$item],
                'links' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 1,
                    'last_page' => 1,
                ],
            ]);
        });
    }

    public function test_application_uses_japan_timezone(): void
    {
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $this->assertSame('Asia/Tokyo', date_default_timezone_get());
        $this->assertSame('+09:00', config('database.connections.mysql.timezone'));
    }

    public function test_user_can_log_in_and_fetch_the_authenticated_account(): void
    {
        User::factory()->create([
            'login_id' => 'admin',
            'password' => 'password1234',
        ]);

        $this->postJson('/api/login', [
            'login_id' => 'admin',
            'password' => 'password1234',
        ])->assertOk()->assertJsonPath('user.loginId', 'admin');

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.loginId', 'admin');
    }

    public function test_all_survey_history_is_returned_without_a_retention_limit(): void
    {
        $this->actingAs(User::factory()->create());
        Survey::factory()->count(101)->create();

        $this->getJson('/api/surveys')
            ->assertOk()
            ->assertJsonCount(101, 'data');

        $this->assertDatabaseCount('surveys', 101);
    }

    public function test_named_import_setting_is_saved_with_product_and_sku_data(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/import-settings', $this->targetPayload())
            ->assertCreated()
            ->assertJsonPath('name', '売上TOP20')
            ->assertJsonPath('productCodes.0', 'A-1001')
            ->assertJsonPath('products.0.skus.0.skuCode', 'A-1001-01-M');

        $this->assertDatabaseHas('products', ['product_code' => 'A-1001']);
        $this->assertDatabaseHas('skus', ['sku_code' => 'A-1001-01-M']);
        $this->assertSame(1, ImportTarget::query()->count());

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/items/lookup')
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['item_nos'] === ['A-1001']);
    }

    public function test_multiple_import_settings_can_use_the_same_product(): void
    {
        $this->actingAs(User::factory()->create());

        $firstId = $this->postJson('/api/import-settings', $this->targetPayload('売上TOP20'))
            ->assertCreated()
            ->json('id');
        $secondId = $this->postJson('/api/import-settings', $this->targetPayload('サングラス'))
            ->assertCreated()
            ->json('id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertDatabaseCount('import_settings', 2);
        $this->assertDatabaseCount('import_targets', 2);
        $this->getJson('/api/import-settings')
            ->assertOk()
            ->assertJsonCount(2, 'settings');
    }

    public function test_import_uses_the_union_of_all_product_groups_and_keeps_both_products_on_same_day(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $firstItem = $this->crossWalkerItem();
        $secondItem = $this->crossWalkerItem();
        $secondItem['item_no'] = 'B-2001';
        $secondItem['skus'] = [[
            'sku_code' => 'B-2001-01-M',
            'child_asin' => 'B000000003',
            'status' => 'active',
            'tq_item_no' => 'B2001',
            'tq_color_no' => '01',
            'tq_size' => 'M',
        ]];
        $this->crossWalkerItems = [$firstItem, $secondItem];
        $groupA = $this->postJson('/api/import-settings', ['name' => 'Aグループ', 'product_codes' => ['A-1001']])->assertCreated()->json('id');
        $groupB = $this->postJson('/api/import-settings', ['name' => 'Bグループ', 'product_codes' => ['B-2001']])->assertCreated()->json('id');
        $overlap = $this->postJson('/api/import-settings', ['name' => '共通', 'product_codes' => ['A-1001']])->assertCreated()->json('id');

        $first = $this->post('/api/surveys', [
            'files' => [UploadedFile::fake()->createWithContent('boss.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,5,1\n自社倉庫,B-2001-01-M,8,2\n自社倉庫,OUTSIDE,9,0\n")],
        ])->assertCreated()->assertJsonCount(2, 'data.products');
        $this->assertNull($first->json('data.importSettingName'));
        $this->assertSame(['A-1001', 'B-2001'], collect($first->json('data.products'))->pluck('productCode')->all());
        $this->assertSame(4, $first->json('data.products.0.skus.0.stock.bossOwn'));
        $this->assertSame(6, $first->json('data.products.1.skus.0.stock.bossOwn'));
        $this->assertSame(['setting:'.$groupA, 'setting:'.$overlap], collect($first->json('data.products.0.groups'))->pluck('key')->all());
        $this->assertSame(['setting:'.$groupB], collect($first->json('data.products.1.groups'))->pluck('key')->all());

        $second = $this->post('/api/surveys', [
            'import_setting_id' => $groupB,
            'files' => [UploadedFile::fake()->createWithContent('ec.csv', "品番,カラーNo,サイズ,キープ残数\nA1001,01,M,3\nB2001,01,M,7\n")],
        ])->assertOk()->assertJsonCount(2, 'data.products');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(4, $second->json('data.products.0.skus.0.stock.bossOwn'));
        $this->assertSame(6, $second->json('data.products.1.skus.0.stock.bossOwn'));
        $this->assertSame(3, $second->json('data.products.0.skus.0.stock.ecStock'));
        $this->assertSame(7, $second->json('data.products.1.skus.0.stock.ecStock'));
        $this->assertDatabaseCount('surveys', 1);
        $this->assertDatabaseCount('survey_products', 2);
        $this->assertDatabaseCount('survey_skus', 2);
        $this->deleteJson('/api/import-settings/'.$groupB)->assertNoContent();
        $this->getJson('/api/surveys/'.$second->json('data.id'))
            ->assertOk()->assertJsonPath('data.products.1.productCode', 'B-2001')
            ->assertJsonPath('data.products.1.groups.0.name', 'Bグループ');
    }

    public function test_file_import_after_an_amazon_only_survey_updates_the_same_survey(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:05:00', 'Asia/Tokyo'));
        $this->postJson('/api/import-settings', $this->targetPayload())->assertCreated();
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'scheduled',
            'snapshot_date' => '2026-10-19',
            'basis_at' => '2026-10-19 09:00:00',
            'completed_at' => '2026-10-19 09:05:00',
        ]);
        $import->snapshots()->create([
            'product_code' => 'A-1001',
            'sku_code' => 'A-1001-01-M',
            'asin' => 'B000000001',
            'amazon_own_stock' => 4,
            'amazon_fba_stock' => 6,
            'basis_at' => $import->basis_at,
        ]);
        $amazonOnly = app(InventoryImportService::class)->createFromAmazonImport($import);
        $this->assertNotNull($amazonOnly);
        $this->getJson('/api/surveys/'.$amazonOnly->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.files')
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 4)
            ->assertJsonPath('data.products.0.skus.0.stock.bossOwn', null);
        SurveyProduct::query()->update(['memo' => '再入荷待ち']);

        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00', 'Asia/Tokyo'));
        $response = $this->post('/api/surveys', [
            'files' => [UploadedFile::fake()->createWithContent('boss.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,5,1\n")],
        ])->assertOk();

        $this->assertSame((string) $amazonOnly->id, $response->json('data.id'));
        $this->assertSame($amazonOnly->executed_at->toISOString(), $response->json('data.executedAt'));
        $this->assertSame(4, $response->json('data.products.0.skus.0.stock.bossOwn'));
        $this->assertSame(4, $response->json('data.products.0.skus.0.stock.amazonOwn'));
        $this->assertSame(6, $response->json('data.products.0.skus.0.stock.amazonFba'));
        $this->assertNull($response->json('data.products.0.skus.0.stock.ecStock'));
        $this->assertSame('再入荷待ち', $response->json('data.products.0.memo'));
        $this->assertDatabaseCount('surveys', 1);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function nonSpApiSurveys(): array
    {
        return [
            'legacy five-file survey' => [['amazon_source' => Survey::AMAZON_SOURCE_LEGACY_FILE, 'includes_amazon_inventory' => true]],
            'survey with an undetermined source' => [['amazon_source' => null, 'includes_amazon_inventory' => true]],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('nonSpApiSurveys')]
    public function test_rerun_refuses_surveys_that_do_not_use_sp_api_amazon_inventory(array $attributes): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $setting = ImportSetting::query()->create(['name' => '別設定']);
        $survey = Survey::factory()->create(['executed_at' => '2026-10-01 10:00:00'] + $attributes);
        $product = SurveyProduct::factory()->for($survey)->create(['product_code' => 'A-1001']);
        $sku = SurveySku::factory()->for($product, 'product')->create(['sku_code' => 'A-1001-01-M', 'amazon_own_stock' => 11, 'amazon_fba_stock' => 12, 'boss_own_stock' => 3]);
        Storage::disk('local')->put('survey-imports/'.$survey->id.'/boss.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,9,0\n");
        $survey->files()->create([
            'file_type' => '倉庫毎の在庫数レポート',
            'original_name' => 'boss.csv',
            'disk' => 'local',
            'stored_path' => 'survey-imports/'.$survey->id.'/boss.csv',
            'mime_type' => 'text/csv',
            'size_bytes' => 60,
            'uploaded_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        $this->postJson('/api/surveys/'.$survey->id.'/rerun', ['import_setting_id' => $setting->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.survey.0', 'Amazon在庫をファイルで取り込んだ旧方式の調査のため、再照合できません。');

        Http::assertNothingSent();
        $fresh = $survey->fresh();
        $this->assertSame($attributes['amazon_source'], $fresh->amazon_source);
        $this->assertTrue($fresh->includes_amazon_inventory);
        $this->assertSame([11, 12, 3], [$sku->fresh()->amazon_own_stock, $sku->fresh()->amazon_fba_stock, $sku->fresh()->boss_own_stock]);
        $this->assertDatabaseCount('survey_products', 1);
        $this->assertSame(1, $fresh->files()->count());
    }

    public function test_rerunning_a_survey_from_before_group_memberships_does_not_duplicate_the_same_group(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())->assertCreated()->json('id');
        $surveyId = $this->post('/api/surveys', [
            'files' => [UploadedFile::fake()->createWithContent('boss.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,5,1\n")],
        ])->assertCreated()->json('data.id');
        // 改修前の調査: 品番ごとのグループ所属がなく、当時のグループ名だけを持つ
        Survey::query()->whereKey($surveyId)->update(['import_setting_name' => '売上TOP20']);
        SurveyProduct::query()->update(['group_memberships' => null]);
        $this->getJson('/api/surveys/'.$surveyId)->assertJsonPath('data.groups', [['key' => 'legacy:売上TOP20', 'name' => '売上TOP20']]);

        $this->postJson('/api/surveys/'.$surveyId.'/rerun', ['import_setting_id' => $settingId])
            ->assertOk()
            ->assertJsonPath('data.groups', [['key' => 'setting:'.$settingId, 'name' => '売上TOP20']])
            ->assertJsonCount(1, 'data.products.0.groups');

        $this->assertSame([['key' => 'setting:'.$settingId, 'name' => '売上TOP20', 'sortOrder' => 1]], SurveyProduct::query()->sole()->group_memberships);
    }

    public function test_survey_groups_merge_a_saved_legacy_group_with_the_current_group_of_the_same_name(): void
    {
        $this->actingAs(User::factory()->create());
        $survey = Survey::factory()->spApi()->create();
        SurveyProduct::factory()->for($survey)->create([
            'product_code' => 'A-1001',
            'sort_order' => 1,
            'group_memberships' => [
                ['key' => 'legacy:売上TOP20', 'name' => '売上TOP20', 'sortOrder' => 1],
                ['key' => 'setting:7', 'name' => '売上TOP20', 'sortOrder' => 1],
                ['key' => 'legacy:旧キャンペーン', 'name' => '旧キャンペーン', 'sortOrder' => 2],
            ],
        ]);
        SurveyProduct::factory()->for($survey)->create([
            'product_code' => 'B-2001',
            'sort_order' => 2,
            'group_memberships' => [['key' => 'legacy:売上TOP20', 'name' => '売上TOP20', 'sortOrder' => 2]],
        ]);

        $this->getJson('/api/surveys/'.$survey->id)
            ->assertOk()
            ->assertJsonPath('data.groups', [
                ['key' => 'setting:7', 'name' => '売上TOP20'],
                ['key' => 'legacy:旧キャンペーン', 'name' => '旧キャンペーン'],
            ])
            ->assertJsonPath('data.products.1.groups', [['key' => 'setting:7', 'name' => '売上TOP20', 'sortOrder' => 2]]);
    }

    public function test_import_can_refresh_more_than_fifty_distinct_products_across_groups(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $this->crossWalkerItems = collect(range(1, 51))->map(function (int $number): array {
            $code = sprintf('P-%04d', $number);

            return [
                'item_no' => $code,
                'brand' => 'ALPHA',
                'category' => 'アウター',
                'parent_asin' => '',
                'status' => 'active',
                'skus' => [[
                    'sku_code' => $code.'-M',
                    'child_asin' => '',
                    'status' => 'active',
                    'tq_item_no' => $code,
                    'tq_color_no' => '01',
                    'tq_size' => 'M',
                ]],
                'updated_at' => '2026-09-02T00:00:00.000000Z',
            ];
        })->all();
        $codes = collect($this->crossWalkerItems)->pluck('item_no')->all();
        $groupA = $this->postJson('/api/import-settings', ['name' => 'Aグループ', 'product_codes' => array_slice($codes, 0, 50)])->assertCreated()->json('id');
        $this->postJson('/api/import-settings', ['name' => 'Bグループ', 'product_codes' => array_slice($codes, 50)])->assertCreated();

        $this->post('/api/surveys', [
            'import_setting_id' => $groupA,
            'files' => [UploadedFile::fake()->createWithContent('boss.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n")],
        ])->assertCreated()->assertJsonCount(51, 'data.products')
            ->assertJsonPath('data.products.50.productCode', 'P-0051');
        $this->assertDatabaseHas('survey_products', ['product_code' => 'P-0051', 'sort_order' => 51]);
        $lookupRequests = Http::recorded(fn ($request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/items/lookup'));
        $this->assertCount(4, $lookupRequests);
        foreach ($lookupRequests as [$request]) {
            $this->assertLessThanOrEqual(50, count($request['item_nos']));
        }
    }

    public function test_crosswalker_items_are_searched_with_bearer_authentication_and_pagination(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/crosswalker/items?keyword=A-1001&page=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('products.0.productCode', 'A-1001')
            ->assertJsonPath('products.0.skus.0.tqCode', 'A1001')
            ->assertJsonPath('page', 1)
            ->assertJsonPath('total', 1);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/v1/items?')
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['keyword'] === 'A-1001'
            && $request['status'] === 'active'
            && $request['page'] === 1
            && $request['per_page'] === 20);
    }

    public function test_crosswalker_rate_limit_is_returned_as_a_service_unavailable_error(): void
    {
        $this->actingAs(User::factory()->create());
        $this->crossWalkerResponseStatus = 429;

        $this->getJson('/api/crosswalker/items')
            ->assertStatus(503)
            ->assertJsonPath('message', 'CrossWalker APIの利用上限に達しました。時間をおいて再度お試しください。');
    }

    public function test_three_files_are_imported_with_the_latest_available_amazon_api_snapshot(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())
            ->assertCreated()
            ->json('id');
        $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'amazon_own' => UploadedFile::fake()->createWithContent('在庫商品レポート.txt', "ASIN\t数量\nB000000001\t99\n"),
        ])->assertUnprocessable()->assertJsonValidationErrors('amazon_own');
        $amazonBasisAt = now()->subMinute();
        $amazonImport = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'basis_at' => $amazonBasisAt,
            'snapshot_date' => $amazonBasisAt->toDateString(),
            'completed_at' => $amazonBasisAt,
        ]);
        $amazonImport->snapshots()->create([
            'product_code' => 'A-1001',
            'sku_code' => 'A-1001-01-M',
            'asin' => 'B000000001',
            'amazon_own_stock' => 2,
            'amazon_fba_stock' => 3,
            'basis_at' => $amazonBasisAt,
        ]);

        $response = $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent(
                '倉庫毎の在庫数レポート.csv',
                "倉庫,SKUコード,販売可能数,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,99,10,6\nRFC倉庫,A-1001-01-M,99,7,2\n",
            ),
                UploadedFile::fake()->createWithContent(
                    'KEEP一覧表.csv',
                    "品番,カラーNo,サイズ,キープ残数\nA1001,01,M,6\nA1001,01,M,1\n",
                ),
                UploadedFile::fake()->createWithContent(
                    '在庫一覧照会表.csv',
                    "品番,カラーNo,ｻｲｽﾞ,可能在庫数\nA1001,01,M,8\n",
                )],
        ]);

        $response->assertCreated()
            ->assertJsonCount(1, 'data.products.0.skus')
            ->assertJsonPath('data.includesAmazonInventory', false)
            ->assertJsonPath('data.amazonSource', 'sp_api')
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 2)
            ->assertJsonPath('data.importSettingName', '売上TOP20')
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', 3)
            ->assertJsonPath('data.products.0.skus.0.stock.bossOwn', 4)
            ->assertJsonPath('data.products.0.skus.0.stock.bossRfc', 5)
            ->assertJsonPath('data.products.0.skus.0.stock.ecStock', 7)
            ->assertJsonPath('data.products.0.skus.0.stock.freeStock', 8);

        $this->assertDatabaseHas('survey_skus', [
            'sku_code' => 'A-1001-01-M',
            'amazon_own_stock' => 2,
            'amazon_fba_stock' => 3,
            'boss_own_stock' => 4,
            'boss_rfc_stock' => 5,
            'ec_stock' => 7,
            'free_stock' => 8,
        ]);
        $this->assertDatabaseMissing('survey_skus', ['sku_code' => 'A-1001-01-L']);
        $this->assertSame(3, $response->json('data.files') === null ? 0 : count($response->json('data.files')));
        $this->assertSame(1, SurveySku::query()->count());
        $this->assertDatabaseHas('surveys', [
            'id' => $response->json('data.id'),
            'includes_amazon_inventory' => false,
            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
            'amazon_inventory_import_id' => $amazonImport->id,
        ]);

        $newerBasisAt = now();
        $newerAmazonImport = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'basis_at' => $newerBasisAt,
            'snapshot_date' => $newerBasisAt->toDateString(),
            'completed_at' => $newerBasisAt,
        ]);
        $newerAmazonImport->snapshots()->create([
            'product_code' => 'A-1001',
            'sku_code' => 'A-1001-01-M',
            'asin' => 'B000000001',
            'amazon_own_stock' => 99,
            'amazon_fba_stock' => 99,
            'basis_at' => $newerBasisAt,
        ]);
        $this->getJson('/api/surveys/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 2)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', 3);

        $this->get('/api/surveys/'.$response->json('data.id').'/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sourceExecutedAt = $response->json('data.executedAt');
        $sourceCreatedAt = $response->json('data.createdAt');
        $this->travel(1)->day();

        $rerunSettingId = $this->postJson('/api/import-settings', $this->targetPayload('再照合設定'))->assertCreated()->json('id');
        $rerunResponse = $this->postJson('/api/surveys/'.$response->json('data.id').'/rerun', ['import_setting_id' => $rerunSettingId]);
        $rerunResponse
            ->assertOk()
            ->assertJsonPath('data.importSettingName', '再照合設定')
            ->assertJsonPath('data.executedAt', $sourceExecutedAt)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 99)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', 99)
            ->assertJsonPath('data.amazonSource', 'sp_api')
            ->assertJsonCount(3, 'data.files');
        $this->assertSame($sourceCreatedAt, $rerunResponse->json('data.createdAt'));
        $this->assertSame($response->json('data.id'), $rerunResponse->json('data.id'));
        $this->assertDatabaseHas('surveys', [
            'id' => $rerunResponse->json('data.id'),
            'amazon_inventory_import_id' => $newerAmazonImport->id,
        ]);
        $this->getJson('/api/surveys')
            ->assertOk()
            ->assertJsonPath('data.0.id', $rerunResponse->json('data.id'));
        $this->assertSame(1, SurveySku::query()->count());
        $this->deleteJson('/api/surveys/'.$response->json('data.id').'/files')->assertNoContent();
        $this->postJson('/api/surveys/'.$response->json('data.id').'/rerun', ['import_setting_id' => $rerunSettingId])->assertUnprocessable()->assertJsonPath('errors.files.0', '再照合に使える取込ファイルがないため、再照合できません。');
        $this->deleteJson('/api/import-settings/'.$settingId)->assertNoContent();
        $this->getJson('/api/surveys/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.importSettingName', '再照合設定');
    }

    public function test_three_file_survey_without_an_amazon_snapshot_keeps_amazon_stock_unknown(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())
            ->assertCreated()
            ->json('id');
        $previousBasisAt = now()->subDay();
        $previousImport = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'basis_at' => $previousBasisAt,
            'snapshot_date' => $previousBasisAt->toDateString(),
            'completed_at' => $previousBasisAt,
        ]);
        $previousImport->snapshots()->create([
            'product_code' => 'A-1001',
            'sku_code' => 'A-1001-01-M',
            'asin' => 'B000000001',
            'amazon_own_stock' => 12,
            'amazon_fba_stock' => 13,
            'basis_at' => $previousBasisAt,
        ]);

        $response = $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent(
                '倉庫毎の在庫数レポート.csv',
                "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,4,1\n",
            ),
                UploadedFile::fake()->createWithContent(
                    'KEEP一覧表.csv',
                    "品番,カラーNo,サイズ,キープ残数\nA1001,01,M,2\n",
                ),
                UploadedFile::fake()->createWithContent(
                    '在庫一覧照会表.csv',
                    "品番,カラーNo,ｻｲｽﾞ,可能在庫数\nA1001,01,M,3\n",
                )],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amazonSource', 'sp_api')
            ->assertJsonPath('data.amazonInventoryStatus', 'pending')
            ->assertJsonPath('data.amazonBasisAt', null)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', null)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', null)
            ->assertJsonPath('data.products.0.skus.0.stock.bossOwn', 3);
        $this->assertDatabaseHas('surveys', [
            'id' => $response->json('data.id'),
            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
            'amazon_inventory_import_id' => null,
        ]);
        $this->assertDatabaseHas('survey_skus', [
            'survey_product_id' => Survey::query()->findOrFail($response->json('data.id'))->products()->first()->id,
            'amazon_own_stock' => null,
            'amazon_fba_stock' => null,
        ]);

        $this->travel(1)->day();
        $nextBasisAt = now();
        $nextImport = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'basis_at' => $nextBasisAt,
            'snapshot_date' => $nextBasisAt->toDateString(),
            'completed_at' => $nextBasisAt,
        ]);
        $nextImport->snapshots()->create([
            'product_code' => 'A-1001',
            'sku_code' => 'A-1001-01-M',
            'asin' => 'B000000001',
            'amazon_own_stock' => 20,
            'amazon_fba_stock' => 21,
            'basis_at' => $nextBasisAt,
        ]);
        $rerun = $this->postJson('/api/surveys/'.$response->json('data.id').'/rerun', ['import_setting_id' => $settingId]);
        $rerun->assertOk()
            ->assertJsonPath('data.executedAt', $response->json('data.executedAt'))
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', null);
        $this->assertDatabaseHas('surveys', [
            'id' => $rerun->json('data.id'),
            'amazon_inventory_import_id' => null,
        ]);
    }

    public function test_editor_can_import_each_file_type_and_rerun(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())->assertCreated()->json('id');
        $cases = [
            ['report-1.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,6,2\n", '倉庫毎の在庫数レポート', 'bossOwn', 4],
            ['report-2.csv', "品番,カラーNo,サイズ,キープ残数\nA1001,01,M,7\n", 'KEEP一覧表', 'ecStock', 7],
            ['report-3.csv', "品番,カラーNo,ｻｲｽﾞ,可能在庫数\nA1001,01,M,8\n", '在庫一覧照会表', 'freeStock', 8],
        ];

        foreach ($cases as [$name, $contents, $type, $field, $quantity]) {
            $response = $this->post('/api/surveys', [
                'import_setting_id' => $settingId,
                'files' => [UploadedFile::fake()->createWithContent($name, $contents)],
            ])->assertStatus($name === 'report-1.csv' ? 201 : 200)->assertJsonCount($name === 'report-1.csv' ? 1 : ($name === 'report-2.csv' ? 2 : 3), 'data.files')
                ->assertJsonFragment(['type' => $type])
                ->assertJsonPath('data.products.0.skus.0.stock.'.$field, $quantity)
                ->assertJsonPath('data.products.0.skus.0.stock.bossRfc', 0);
            if ($type === '倉庫毎の在庫数レポート') {
                $response->assertJsonPath('data.products.0.skus.0.stock.ecStock', null)
                    ->assertJsonPath('data.products.0.skus.0.stock.freeStock', null);
            }
            $this->postJson('/api/surveys/'.$response->json('data.id').'/rerun', ['import_setting_id' => $settingId])
                ->assertOk()->assertJsonCount($name === 'report-1.csv' ? 1 : ($name === 'report-2.csv' ? 2 : 3), 'data.files')
                ->assertJsonPath('data.products.0.skus.0.stock.'.$field, $quantity);
        }
        $this->assertDatabaseCount('surveys', 1);
        $this->assertDatabaseCount('survey_files', 3);
    }

    public function test_same_day_import_replaces_its_file_and_leaves_other_sources_pending(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())->assertCreated()->json('id');
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Asia/Tokyo'));
        $first = $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent('initial.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,6,2\n")],
        ])->assertCreated()
            ->assertJsonPath('data.products.0.skus.0.stock.bossOwn', 4)
            ->assertJsonPath('data.products.0.skus.0.stock.bossRfc', 0)
            ->assertJsonPath('data.products.0.skus.0.stock.freeStock', null)
            ->assertJsonPath('data.products.0.skus.0.stock.ecStock', null);
        $surveyId = $first->json('data.id');
        $oldPath = Survey::query()->findOrFail($surveyId)->files()->firstOrFail()->stored_path;
        $date = '2026-10-16';
        $this->getJson("/api/inventory-trends?product_code=A-1001&from={$date}&to={$date}&scope=free")
            ->assertOk()->assertJsonPath('data.series.0.points.0.quantity', null);
        $csvRows = app(InventoryTrendCsvExporter::class)->rows(
            'A-1001', CarbonImmutable::parse($date), CarbonImmutable::parse($date),
        );
        $this->assertNull($csvRows[0]['freeStock']);
        $this->assertNull($csvRows[0]['ecStock']);

        $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent('invalid.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,1,2\n")],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('surveys', 1);
        $this->assertDatabaseHas('survey_files', ['survey_id' => $surveyId, 'original_name' => 'initial.csv']);
        Storage::disk('local')->assertExists($oldPath);
        $this->putJson('/api/surveys/'.$surveyId.'/products/'.$first->json('data.products.0.id').'/memo', ['memo' => '商品メモ'])->assertOk();
        $this->putJson('/api/surveys/'.$surveyId.'/skus/'.$first->json('data.products.0.skus.0.id').'/memo', ['memo' => 'SKUメモ'])->assertOk();

        $this->travel(1)->hour();
        $second = $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent('replacement.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,9,2\n")],
        ])->assertOk()
            ->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.fileName', 'replacement.csv')
            ->assertJsonPath('data.products.0.skus.0.stock.bossOwn', 7)
            ->assertJsonPath('data.products.0.skus.0.stock.freeStock', null)
            ->assertJsonPath('data.products.0.memo', '商品メモ')
            ->assertJsonPath('data.products.0.skus.0.memo', 'SKUメモ');
        $this->assertSame($surveyId, $second->json('data.id'));
        $this->assertSame($first->json('data.executedAt'), $second->json('data.executedAt'));
        $this->assertSame($first->json('data.createdAt'), $second->json('data.createdAt'));
        $this->assertNotSame($first->json('data.updatedAt'), $second->json('data.updatedAt'));
        $this->assertDatabaseCount('surveys', 1);
        $this->assertDatabaseCount('survey_files', 1);
        $this->assertDatabaseHas('surveys', ['id' => $surveyId, 'survey_date' => $date]);
        Storage::disk('local')->assertMissing($oldPath);

        $this->travel(1)->day();
        $third = $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent('next-day.csv', "品番,カラーNo,サイズ,キープ残数\nA1001,01,M,3\n")],
        ])->assertCreated()
            ->assertJsonPath('data.products.0.skus.0.stock.bossOwn', null)
            ->assertJsonPath('data.products.0.skus.0.stock.ecStock', 3);
        $this->assertNotSame($surveyId, $third->json('data.id'));
        $this->assertDatabaseCount('surveys', 2);
    }

    public function test_file_contents_determine_type_and_reject_unknown_or_duplicate_types(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())->assertCreated()->json('id');
        $boss = "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,6,2\n";

        $this->post('/api/surveys', ['import_setting_id' => $settingId])
            ->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'boss' => UploadedFile::fake()->createWithContent('old.csv', $boss),
        ])->assertUnprocessable()->assertJsonValidationErrors(['boss', 'files']);
        $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent('unknown.csv', "ASIN,数量\nB000000001,5\n")],
        ])->assertUnprocessable()->assertJsonPath('errors.files.0', 'unknown.csvの種類を内容から判定できません。ヘッダーを確認してください。');
        $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [
                UploadedFile::fake()->createWithContent('first.csv', $boss),
                UploadedFile::fake()->createWithContent('second.csv', $boss),
            ],
        ])->assertUnprocessable()->assertJsonPath('errors.files.0', '倉庫毎の在庫数レポートのファイルが複数あります。');
        $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent('ambiguous.csv', "品番,カラーNo,サイズ,キープ残数,可能在庫数\nA1001,01,M,2,3\n")],
        ])->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertDatabaseCount('surveys', 0);
    }

    public function test_shift_jis_headers_are_classified_by_contents(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())->assertCreated()->json('id');
        $contents = mb_convert_encoding("品番,カラーNo,サイズ,キープ残数\nA1001,01,M,9\n", 'SJIS-win', 'UTF-8');

        $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [UploadedFile::fake()->createWithContent('arbitrary.csv', $contents)],
        ])->assertCreated()
            ->assertJsonPath('data.files.0.type', 'KEEP一覧表')
            ->assertJsonPath('data.products.0.skus.0.stock.ecStock', 9);
    }

    public function test_survey_checks_again_after_saving_when_amazon_import_finishes_during_creation(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())->assertCreated()->json('id');

        // 調査のトランザクションがコミットされた直後に、Amazon在庫取込が完了する（保存中には見えなかった取込）
        $completed = null;
        Event::listen(TransactionCommitted::class, function () use (&$completed): void {
            if ($completed !== null || ! Survey::query()->exists()) {
                return;
            }
            $basisAt = now();
            $completed = AmazonInventoryImport::query()->create([
                'status' => 'succeeded',
                'trigger_source' => 'manual',
                'basis_at' => $basisAt,
                'snapshot_date' => $basisAt->toDateString(),
                'completed_at' => $basisAt,
            ]);
            $completed->snapshots()->create([
                'product_code' => 'A-1001',
                'sku_code' => 'A-1001-01-M',
                'asin' => 'B000000001',
                'amazon_own_stock' => 8,
                'amazon_fba_stock' => 9,
                'basis_at' => $basisAt,
            ]);
        });

        $response = $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [
                UploadedFile::fake()->createWithContent('倉庫毎の在庫数レポート.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,4,1\n"),
                UploadedFile::fake()->createWithContent('KEEP一覧表.csv', "品番,カラーNo,サイズ,キープ残数\nA1001,01,M,2\n"),
                UploadedFile::fake()->createWithContent('在庫一覧照会表.csv', "品番,カラーNo,ｻｲｽﾞ,可能在庫数\nA1001,01,M,3\n"),
            ],
        ]);

        $this->assertNotNull($completed);
        $response->assertCreated()
            ->assertJsonPath('data.amazonSource', 'sp_api')
            ->assertJsonPath('data.amazonInventoryImportId', (string) $completed->id)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 8)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', 9);
        $this->assertDatabaseHas('survey_skus', [
            'survey_product_id' => Survey::query()->findOrFail($response->json('data.id'))->products()->first()->id,
            'amazon_own_stock' => 8,
            'amazon_fba_stock' => 9,
        ]);
    }

    public function test_post_save_sync_failure_does_not_make_a_committed_survey_look_failed(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $settingId = $this->postJson('/api/import-settings', $this->targetPayload())->assertCreated()->json('id');
        $sync = new class(new BulkRowWriter) extends SurveyAmazonInventorySyncService
        {
            public function syncSurveyIfNewerImport(int $surveyId): ?int
            {
                throw new \RuntimeException('一時的な再同期エラー');
            }
        };
        $this->app->instance(SurveyAmazonInventorySyncService::class, $sync);

        $response = $this->post('/api/surveys', [
            'import_setting_id' => $settingId,
            'files' => [
                UploadedFile::fake()->createWithContent('倉庫毎の在庫数レポート.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1001-01-M,4,1\n"),
                UploadedFile::fake()->createWithContent('KEEP一覧表.csv', "品番,カラーNo,サイズ,キープ残数\nA1001,01,M,2\n"),
                UploadedFile::fake()->createWithContent('在庫一覧照会表.csv', "品番,カラーNo,ｻｲｽﾞ,可能在庫数\nA1001,01,M,3\n"),
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', null);
        $this->assertDatabaseCount('surveys', 1);
        $this->assertDatabaseHas('surveys', ['id' => $response->json('data.id'), 'amazon_inventory_import_id' => null]);
    }

    public function test_existing_survey_keeps_its_saved_amazon_stock(): void
    {
        $this->actingAs(User::factory()->create());
        $survey = Survey::factory()->create();
        $product = SurveyProduct::factory()->for($survey)->create();
        SurveySku::factory()->create([
            'survey_product_id' => $product->id,
            'amazon_own_stock' => 4,
            'amazon_fba_stock' => 7,
        ]);

        $this->getJson('/api/surveys/'.$survey->id)
            ->assertOk()
            ->assertJsonPath('data.includesAmazonInventory', true)
            ->assertJsonPath('data.amazonSource', 'survey')
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 4)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', 7);

        $survey->update(['includes_amazon_inventory' => false]);
        $this->getJson('/api/surveys/'.$survey->id)
            ->assertOk()
            ->assertJsonPath('data.includesAmazonInventory', true)
            ->assertJsonPath('data.amazonSource', 'survey')
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 4)
            ->assertJsonPath('data.products.0.skus.0.stock.amazonFba', 7);
    }

    /** @return array<string, mixed> */
    private function targetPayload(string $name = '売上TOP20'): array
    {
        return [
            'name' => $name,
            'product_codes' => ['A-1001'],
        ];
    }

    /** @return array<string, mixed> */
    private function crossWalkerItem(): array
    {
        return [
            'item_no' => 'A-1001',
            'brand' => 'ALPHA',
            'category' => 'アウター',
            'parent_asin' => 'B000001000',
            'status' => 'active',
            'skus' => [[
                'sku_code' => 'A-1001-01-M',
                'child_asin' => 'B000000001',
                'status' => 'active',
                'tq_item_no' => 'A1001',
                'tq_color_no' => '01',
                'tq_size' => 'M',
            ], [
                'sku_code' => 'A-1001-01-L',
                'child_asin' => 'B000000002',
                'status' => 'inactive',
                'tq_item_no' => 'A1001',
                'tq_color_no' => '01',
                'tq_size' => 'L',
            ]],
            'updated_at' => '2026-09-02T00:00:00.000000Z',
        ];
    }
}
