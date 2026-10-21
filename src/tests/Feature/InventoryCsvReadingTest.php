<?php

namespace Tests\Feature;

use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\SurveySku;
use App\Services\InventoryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 取込CSVを1行ずつ読む処理。Shift_JIS（CP932）の変換、行番号付きのエラー、重複の検出が、ファイル全体を読んでいたときと同じになること。
 */
class InventoryCsvReadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1',
            'services.crosswalker.api_key' => 'test-api-key',
        ]);
        Http::fake(fn (ClientRequest $request) => Http::response(['data' => [[
            'item_no' => 'A', 'brand' => 'BRAND', 'category' => 'CATEGORY', 'parent_asin' => null, 'status' => 'active', 'updated_at' => null,
            'skus' => [
                ['sku_code' => 'A-1', 'child_asin' => null, 'status' => 'active', 'tq_item_no' => 'TA', 'tq_color_no' => '01', 'tq_size' => 'Ｍ'],
                ['sku_code' => 'A-2', 'child_asin' => null, 'status' => 'active', 'tq_item_no' => 'TA', 'tq_color_no' => '01', 'tq_size' => 'Ｌ'],
            ],
        ]]]));
        $setting = ImportSetting::query()->create(['name' => 'G']);
        $product = Product::query()->create(['product_code' => 'A', 'brand' => '', 'category' => '', 'status' => 'active', 'synced_at' => now()]);
        ImportTarget::query()->create(['import_setting_id' => $setting->id, 'product_id' => $product->id, 'sort_order' => 1]);
    }

    public function test_large_shift_jis_files_with_quoted_line_breaks_are_read_row_by_row(): void
    {
        // 一時ストリームが一時ファイルへ移る 2 MiB と、変換の区切り（約1 MiB）を超える大きさにし、セルの中の改行と全角のサイズを含める
        $filler = str_repeat("TX,01,Ｍ,対象外の商品,1\r\n", 110_000);
        $free = "品番,カラーNo,ｻｲｽﾞ,品名,可能在庫数\r\n{$filler}TA,01,Ｍ,\"改行を\r\n含む品名\",3\r\nTA,01,Ｌ,ソ表示（2バイト目が0x5C）,4\r\n";
        $keep = "品番,カラーNo,サイズ,キープ残数\r\nTA,01,Ｍ,2\r\nTA,01,Ｍ,5\r\n";
        $this->assertGreaterThan(2 * 1024 * 1024, strlen($free));

        $this->import([$this->sjis('在庫一覧照会表.csv', $free), $this->sjis('KEEP一覧表.csv', $keep)]);

        $this->assertSame(
            ['A-1' => [3, 7], 'A-2' => [4, 0]],
            SurveySku::query()->orderBy('sku_code')->get()->mapWithKeys(fn (SurveySku $sku): array => [$sku->sku_code => [$sku->free_stock, $sku->ec_stock]])->all(),
        );
    }

    public function test_utf8_files_with_a_byte_order_mark_are_read_as_is(): void
    {
        $this->import([UploadedFile::fake()->createWithContent('free.csv', "\u{FEFF}品番,カラーNo,ｻｲｽﾞ,可能在庫数\nTA,01,Ｍ,6\n")]);

        $this->assertSame(6, SurveySku::query()->where('sku_code', 'A-1')->value('free_stock'));
    }

    public function test_errors_name_the_csv_row_number(): void
    {
        // 2行目のセルに改行があっても、行番号はCSVの行（レコード）で数える
        $header = "品番,カラーNo,ｻｲｽﾞ,品名,可能在庫数\r\n";
        $this->assertImportFails($header."TX,01,Ｍ,\"複数行\r\nの品名\",1\r\nTA,01,Ｍ,商品,abc\r\n", '在庫一覧照会表.csvの3行目の数量が数値ではありません。');
        $this->assertImportFails($header."TA,01,Ｍ,商品,1\r\nTA,01,Ｍ,商品,-2\r\n", '在庫一覧照会表.csvの3行目の数量がマイナスです。');
        $this->assertImportFails($header."TA,01,Ｍ,商品,1\r\nTX,01,Ｍ,商品,1\r\nTA,01,Ｍ,商品,2\r\n", '在庫一覧照会表.csvの4行目で対象SKUが重複しています。');
    }

    /** @param list<UploadedFile> $files */
    private function import(array $files): void
    {
        app(InventoryImportService::class)->import($files, ImportSetting::query()->sole());
    }

    private function assertImportFails(string $csv, string $message): void
    {
        try {
            $this->import([$this->sjis('在庫一覧照会表.csv', $csv)]);
            $this->fail('取込が失敗しませんでした。');
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()['files']);
        }
    }

    private function sjis(string $name, string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, mb_convert_encoding($contents, 'SJIS-win', 'UTF-8'));
    }
}
