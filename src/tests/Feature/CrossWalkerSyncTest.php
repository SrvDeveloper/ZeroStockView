<?php

namespace Tests\Feature;

use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\Sku;
use App\Services\CrossWalkerClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CrossWalker の品番・SKU を、品番マスタ（products・skus）へ反映する処理。
 * SKUマスタには sku_code・child_asin・（tq_item_no, tq_color_no, tq_size）・（product_id, sort_order）の一意制約があるので、
 * 値が入れ替わる・品番をまたいで移る場合も、途中で制約に違反せず反映できることを確かめる。
 */
class CrossWalkerSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1',
            'services.crosswalker.api_key' => 'test-api-key',
        ]);
        Http::fake(fn (ClientRequest $request) => Http::response([
            'data' => array_values(array_intersect_key($this->items, array_flip($request['item_nos'] ?? []))),
        ]));
    }

    public function test_first_sync_creates_the_skus(): void
    {
        $this->items = ['A' => $this->item('A', [$this->sku('A-1', 'B1', 'TA', '01', 'M'), $this->sku('A-2', null, 'TA', '01', 'L')])];

        $this->refresh(['A']);

        $this->assertSame([['A-1', 'B1', 'TA', '01', 'M', 1], ['A-2', null, 'TA', '01', 'L', 2]], $this->skuRows('A'));
    }

    public function test_two_skus_can_swap_their_asin_tq_key_and_order(): void
    {
        $this->items = ['A' => $this->item('A', [$this->sku('A-1', 'B1', 'TA', '01', 'M'), $this->sku('A-2', 'B2', 'TA', '01', 'L')])];
        $this->refresh(['A']);

        // A-1 と A-2 で、ASIN・TQのサイズ・並び順をすべて入れ替える
        $this->items = ['A' => $this->item('A', [$this->sku('A-2', 'B1', 'TA', '01', 'M'), $this->sku('A-1', 'B2', 'TA', '01', 'L')])];
        $this->refresh(['A']);

        $this->assertSame([['A-2', 'B1', 'TA', '01', 'M', 1], ['A-1', 'B2', 'TA', '01', 'L', 2]], $this->skuRows('A'));
    }

    public function test_a_sku_and_an_asin_can_move_to_a_product_that_is_synced_earlier(): void
    {
        $this->items = [
            'A' => $this->item('A', [$this->sku('A-1', 'B1', 'TA', '01', 'M')]),
            'B' => $this->item('B', [$this->sku('B-1', 'B9', 'TB', '01', 'M'), $this->sku('MOVE', 'B8', 'TB', '01', 'L')]),
        ];
        $this->refresh(['A', 'B']);

        // B にあった SKU「MOVE」と ASIN「B9」が A に移る（A のほうが先に反映される）
        $this->items = [
            'A' => $this->item('A', [$this->sku('A-1', 'B9', 'TA', '01', 'M'), $this->sku('MOVE', 'B8', 'TB', '01', 'L')]),
            'B' => $this->item('B', [$this->sku('B-1', 'B1', 'TB', '01', 'M')]),
        ];
        $this->refresh(['A', 'B']);

        $this->assertSame([['A-1', 'B9', 'TA', '01', 'M', 1], ['MOVE', 'B8', 'TB', '01', 'L', 2]], $this->skuRows('A'));
        $this->assertSame([['B-1', 'B1', 'TB', '01', 'M', 1]], $this->skuRows('B'));
    }

    public function test_removed_skus_are_deleted_and_changed_values_are_saved(): void
    {
        $this->items = ['A' => $this->item('A', [$this->sku('A-1', 'B1', 'TA', '01', 'M'), $this->sku('A-2', 'B2', 'TA', '01', 'L')])];
        $this->refresh(['A']);

        $changed = $this->sku('A-1', 'B1', 'TA', '01', 'M');
        $changed['status'] = 'inactive';
        $this->items = ['A' => $this->item('A', [$changed, $this->sku('A-3', null, 'TA', '02', 'S')], brand: 'NEW BRAND')];
        $this->refresh(['A']);

        $this->assertSame([['A-1', 'B1', 'TA', '01', 'M', 1], ['A-3', null, 'TA', '02', 'S', 2]], $this->skuRows('A'));
        $this->assertSame('inactive', Sku::query()->where('sku_code', 'A-1')->value('status'));
        $this->assertSame('NEW BRAND', Product::query()->where('product_code', 'A')->value('brand'));
    }

    public function test_an_unchanged_sync_writes_nothing_but_the_sync_time(): void
    {
        $this->items = [
            'A' => $this->item('A', [$this->sku('A-1', 'B1', 'TA', '01', 'M'), $this->sku('A-2', null, 'TA', '01', 'L')]),
            'B' => $this->item('B', [$this->sku('B-1', 'B2', 'TB', '01', 'M')]),
        ];
        $this->refresh(['A', 'B']);
        $skuIds = Sku::query()->orderBy('id')->pluck('id')->all();
        $this->travel(1)->hours();

        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $this->refresh(['A', 'B']);

        // 品番の同期日時をまとめて1回更新するだけで、SKUは消さず・入れ直さない
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('update "products"', $writes[0]);
        $this->assertSame($skuIds, Sku::query()->orderBy('id')->pluck('id')->all());
        $this->assertEquals(now()->startOfSecond(), Product::query()->where('product_code', 'A')->first()->synced_at);
    }

    /** @param list<string> $codes 品番グループの対象にして、CrossWalker から同期する */
    private function refresh(array $codes): void
    {
        $setting = ImportSetting::query()->firstOrCreate(['name' => 'G']);
        foreach ($codes as $index => $code) {
            $product = Product::query()->firstOrCreate(['product_code' => $code], ['brand' => '', 'category' => '', 'status' => 'active', 'synced_at' => now()]);
            ImportTarget::query()->firstOrCreate(['product_id' => $product->id], ['import_setting_id' => $setting->id, 'sort_order' => $index + 1]);
        }
        /** @var Collection<int, ImportTarget> $targets */
        $targets = ImportTarget::query()->with('product')->orderBy('sort_order')->get();
        app(CrossWalkerClient::class)->refresh($targets);
    }

    /** @return list<array{0: string, 1: string|null, 2: string, 3: string, 4: string, 5: int}> */
    private function skuRows(string $productCode): array
    {
        return Sku::query()
            ->whereRelation('product', 'product_code', $productCode)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Sku $sku): array => [$sku->sku_code, $sku->child_asin, $sku->tq_item_no, $sku->tq_color_no, $sku->tq_size, $sku->sort_order])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $skus
     * @return array<string, mixed>
     */
    private function item(string $code, array $skus, string $brand = 'BRAND'): array
    {
        return ['item_no' => $code, 'brand' => $brand, 'category' => 'CATEGORY', 'parent_asin' => null, 'status' => 'active', 'skus' => $skus, 'updated_at' => '2026-10-01T00:00:00.000000Z'];
    }

    /** @return array<string, mixed> */
    private function sku(string $code, ?string $asin, string $tqItem, string $tqColor, string $tqSize): array
    {
        return ['sku_code' => $code, 'child_asin' => $asin, 'status' => 'active', 'tq_item_no' => $tqItem, 'tq_color_no' => $tqColor, 'tq_size' => $tqSize];
    }
}
