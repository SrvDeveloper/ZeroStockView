<?php

namespace Tests\Feature;

use App\Models\ImportTarget;
use App\Models\SurveySku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiBackendTest extends TestCase
{
    use RefreshDatabase;

    private int $crossWalkerResponseStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1',
            'services.crosswalker.api_key' => 'test-api-key',
        ]);

        $item = $this->crossWalkerItem();
        Http::fake(function (ClientRequest $request) use ($item) {
            if ($this->crossWalkerResponseStatus !== 200) {
                return Http::response(['message' => 'CrossWalker error'], $this->crossWalkerResponseStatus);
            }

            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/items/lookup')) {
                return Http::response(['data' => [$item]]);
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

    public function test_import_targets_are_saved_with_product_and_sku_data(): void
    {
        $this->actingAs(User::factory()->create());

        $this->putJson('/api/import-targets', $this->targetPayload())
            ->assertOk()
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

    public function test_five_files_are_imported_into_a_persisted_survey(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $this->putJson('/api/import-targets', $this->targetPayload())->assertOk();

        $response = $this->post('/api/surveys', [
            'amazon_own' => UploadedFile::fake()->createWithContent(
                '在庫商品レポート.txt',
                "出品者SKU\tASIN\t価格\t数量\nA-1001-01-M\tB000000001\t1000\t2\n",
            ),
            'amazon_fba' => UploadedFile::fake()->createWithContent(
                'FBA在庫管理レポート.csv',
                "ASIN,Amazon出荷在庫(出荷可)\nB000000001,3\n",
            ),
            'boss' => UploadedFile::fake()->createWithContent(
                '倉庫毎の在庫数レポート.csv',
                "倉庫,SKUコード,販売可能数\n自社倉庫,A-1001-01-M,4\nRFC倉庫,A-1001-01-M,5\n",
            ),
            'ec_stock' => UploadedFile::fake()->createWithContent(
                'KEEP一覧表.csv',
                "品番,カラーNo,サイズ,キープ残数\nA1001,01,M,6\nA1001,01,M,1\n",
            ),
            'free_stock' => UploadedFile::fake()->createWithContent(
                '在庫一覧照会表.csv',
                "品番,カラーNo,ｻｲｽﾞ,可能在庫数\nA1001,01,M,8\n",
            ),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.products.0.skus.0.stock.amazonOwn', 2)
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
        $this->assertSame(5, $response->json('data.files') === null ? 0 : count($response->json('data.files')));
        $this->assertSame(1, SurveySku::query()->count());

        $this->get('/api/surveys/'.$response->json('data.id').'/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** @return array<string, mixed> */
    private function targetPayload(): array
    {
        return [
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
            ]],
            'updated_at' => '2026-09-02T00:00:00.000000Z',
        ];
    }
}
