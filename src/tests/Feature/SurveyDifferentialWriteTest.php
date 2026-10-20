<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\Survey;
use App\Models\SurveySku;
use App\Services\InventoryImportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 同じ日の調査を取り込み直すとき、調査の品番・SKUを作り直さず、変わった行だけを書くこと。
 * 調査SKUには（調査品番, SKUコード）・（調査品番, 並び順）、調査品番には（調査, 品番コード）・（調査, 並び順）の一意制約がある。
 */
class SurveyDifferentialWriteTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-20 12:00:00');
        config([
            'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1',
            'services.crosswalker.api_key' => 'test-api-key',
        ]);
        Http::fake(fn (ClientRequest $request) => Http::response([
            'data' => array_values(array_intersect_key($this->items, array_flip($request['item_nos'] ?? []))),
        ]));
        $this->items = [
            'A' => $this->item('A', [$this->sku('A-1', 'BA1', 'TA', '01', 'M'), $this->sku('A-2', 'BA2', 'TA', '01', 'L')]),
            'B' => $this->item('B', [$this->sku('B-1', 'BB1', 'TB', '01', 'M')]),
        ];
        $this->setTargets(['A', 'B']);
    }

    public function test_reimporting_the_same_day_keeps_rows_and_memos_and_writes_only_changed_rows(): void
    {
        $survey = $this->import(['A-1' => 1, 'A-2' => 2, 'B-1' => 3]);
        $productIds = $survey->products()->pluck('id', 'product_code')->all();
        $skuIds = $this->skuIds();
        $survey->products()->where('product_code', 'A')->update(['memo' => '品番メモ']);
        SurveySku::query()->where('sku_code', 'A-2')->update(['memo' => 'SKUメモ']);

        $writes = $this->recordWrites(fn () => $this->import(['A-1' => 1, 'A-2' => 5, 'B-1' => 3]));

        $this->assertSame($productIds, $survey->products()->pluck('id', 'product_code')->all());
        $this->assertSame($skuIds, $this->skuIds());
        $this->assertSame('品番メモ', $survey->products()->where('product_code', 'A')->value('memo'));
        $this->assertSame('SKUメモ', SurveySku::query()->where('sku_code', 'A-2')->value('memo'));
        $this->assertSame(5, SurveySku::query()->where('sku_code', 'A-2')->value('boss_own_stock'));
        // 在庫が変わった 1 行を 1 文で更新するだけで、品番・SKUの行は作り直さない
        $this->assertSame(['update survey_skus' => 1], $this->surveyRowWrites($writes));
    }

    public function test_reordered_added_removed_and_moved_rows_are_written_without_breaking_unique_orders(): void
    {
        $survey = $this->import(['A-1' => 1, 'A-2' => 2, 'B-1' => 3]);
        $a2Id = SurveySku::query()->where('sku_code', 'A-2')->value('id');
        $productBId = $survey->products()->where('product_code', 'B')->value('id');
        SurveySku::query()->where('sku_code', 'A-1')->update(['memo' => '移るSKUのメモ']);

        // A の SKU の並びを入れ替え、A-1 を B へ移し、新しい品番 0（先頭に並ぶ）と SKU A-3 を足す
        $this->items = [
            '0' => $this->item('0', [$this->sku('0-1', null, 'T0', '01', 'M')]),
            'A' => $this->item('A', [$this->sku('A-3', 'BA3', 'TA', '02', 'S'), $this->sku('A-2', 'BA2', 'TA', '01', 'L')]),
            'B' => $this->item('B', [$this->sku('B-1', 'BB1', 'TB', '01', 'M'), $this->sku('A-1', 'BA1', 'TA', '01', 'M')]),
        ];
        $this->setTargets(['0', 'A', 'B']);
        $this->import(['A-1' => 4, 'A-2' => 2, 'B-1' => 3]);

        $this->assertSame([
            ['0', 1, [['0-1', 1]]],
            ['A', 2, [['A-3', 1], ['A-2', 2]]],
            ['B', 3, [['B-1', 1], ['A-1', 2]]],
        ], $survey->products()->orderBy('sort_order')->with('skus')->get()
            ->map(fn ($product): array => [$product->product_code, $product->sort_order, $product->skus->map(fn ($sku): array => [$sku->sku_code, $sku->sort_order])->all()])
            ->all());
        $this->assertSame($a2Id, SurveySku::query()->where('sku_code', 'A-2')->value('id'));
        $this->assertSame($productBId, $survey->products()->where('product_code', 'B')->value('id'));
        $moved = SurveySku::query()->where('sku_code', 'A-1')->firstOrFail();
        $this->assertSame([$productBId, '移るSKUのメモ', 4], [$moved->survey_product_id, $moved->memo, $moved->boss_own_stock]);

        // 品番 B を対象から外すと、その調査SKUも消える
        unset($this->items['B']);
        $this->setTargets(['0', 'A']);
        $this->import([]);
        $this->assertSame(['0', 'A'], $survey->products()->orderBy('sort_order')->pluck('product_code')->all());
        $this->assertSame(['0-1', 'A-2', 'A-3'], SurveySku::query()->orderBy('sku_code')->pluck('sku_code')->all());
    }

    public function test_the_days_amazon_stock_is_written_with_the_rows_instead_of_updated_afterwards(): void
    {
        $this->items['B']['skus'][0]['child_asin'] = null;
        $amazon = AmazonInventoryImport::query()->create([
            'status' => 'succeeded', 'trigger_source' => 'manual',
            'basis_at' => now(), 'snapshot_date' => now()->toDateString(), 'completed_at' => now(),
        ]);
        $amazon->snapshots()->createMany([
            ['product_code' => 'A', 'sku_code' => 'A-1', 'asin' => 'BA1', 'amazon_own_stock' => 0, 'amazon_fba_stock' => 7, 'basis_at' => now()],
            ['product_code' => 'B', 'sku_code' => 'B-1', 'asin' => 'BB1', 'amazon_own_stock' => 2, 'amazon_fba_stock' => 0, 'basis_at' => now()],
        ]);

        $writes = $this->recordWrites(fn () => $this->import(['A-1' => 1]));

        $survey = Survey::query()->sole();
        $this->assertSame($amazon->id, $survey->amazon_inventory_import_id);
        $this->assertSame(
            [['A-1', 'BA1', 0, 7], ['A-2', 'BA2', null, null], ['B-1', 'BB1', 2, 0]],
            SurveySku::query()->orderBy('sku_code')->get()
                ->map(fn (SurveySku $sku): array => [$sku->sku_code, $sku->child_asin, $sku->amazon_own_stock, $sku->amazon_fba_stock])->all(),
        );
        // 行を入れたあとに Amazon の値を 1 行ずつ書き直さない
        $this->assertSame(['insert survey_products' => 1, 'insert survey_skus' => 1], $this->surveyRowWrites($writes));
    }

    /** @param  array<string, int>  $bossStocks  SKUコード => 自社倉庫の在庫 */
    private function import(array $bossStocks): Survey
    {
        $csv = "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n";
        foreach ($bossStocks as $skuCode => $stock) {
            $csv .= "自社倉庫,{$skuCode},{$stock},0\n";
        }

        return app(InventoryImportService::class)->import(
            [UploadedFile::fake()->createWithContent('倉庫毎の在庫数レポート.csv', $csv)],
            ImportSetting::query()->sole(),
        );
    }

    /** @param list<string> $codes */
    private function setTargets(array $codes): void
    {
        $setting = ImportSetting::query()->firstOrCreate(['name' => 'G']);
        $setting->targets()->delete();
        foreach ($codes as $index => $code) {
            $product = Product::query()->firstOrCreate(['product_code' => $code], ['brand' => '', 'category' => '', 'status' => 'active', 'synced_at' => now()]);
            ImportTarget::query()->create(['import_setting_id' => $setting->id, 'product_id' => $product->id, 'sort_order' => $index + 1]);
        }
    }

    /** @return array<string, int> SKUコード => 調査SKUのID */
    private function skuIds(): array
    {
        return SurveySku::query()->orderBy('sku_code')->pluck('id', 'sku_code')->all();
    }

    /** @return list<string> */
    private function recordWrites(callable $callback): array
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $callback();

        return $writes;
    }

    /**
     * 調査の品番・SKUの表への書き込みを、種類と表ごとに数える。
     *
     * @param  list<string>  $writes
     * @return array<string, int>
     */
    private function surveyRowWrites(array $writes): array
    {
        $counts = [];
        foreach ($writes as $sql) {
            if (preg_match('/^\s*(insert into|update|delete from)\s+"(survey_products|survey_skus)"/i', $sql, $matches) === 1) {
                $key = strtolower(strtok($matches[1], ' ')).' '.$matches[2];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $skus
     * @return array<string, mixed>
     */
    private function item(string $code, array $skus): array
    {
        return ['item_no' => $code, 'brand' => 'BRAND', 'category' => 'CATEGORY', 'parent_asin' => null, 'status' => 'active', 'skus' => $skus, 'updated_at' => '2026-10-01T00:00:00.000000Z'];
    }

    /** @return array<string, mixed> */
    private function sku(string $code, ?string $asin, string $tqItem, string $tqColor, string $tqSize): array
    {
        return ['sku_code' => $code, 'child_asin' => $asin, 'status' => 'active', 'tq_item_no' => $tqItem, 'tq_color_no' => $tqColor, 'tq_size' => $tqSize];
    }
}
