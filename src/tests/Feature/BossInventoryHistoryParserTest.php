<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BossInventoryHistory;
use App\Services\BossInventoryHistoryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** BOSS の在庫変動履歴の読込と検証（docs/BOSS在庫の休日補完 BHR-01）。データはすべて架空 */
class BossInventoryHistoryParserTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = '処理日時,処理種別,モール,ショップ,処理番号,SKUコード,倉庫,変動数,変動前実在庫,変動後実在庫,変動前引当済,変動後引当済,変動前実在庫 - 変動前引当済,変動後実在庫 - 変動後引当済';

    public function test_reads_rows_by_sku_and_warehouse_in_processing_order_and_summarizes_the_history(): void
    {
        $history = $this->parse([
            ['2026/10/18 10:00:00', 'API注文引当', '1002', 'A-01', '自社倉庫', 10, 10, 1, 2],
            ['2026/10/17 09:00:00', '在庫調整（増）', '1001', 'A-01', '自社倉庫', 8, 10, 1, 1],
            ['2026/10/17 12:00:00', 'RSL在庫増減', '1003', 'A-01', 'RFC倉庫', 5, 4, 0, 0],
            // BOSS は時を0で埋めないことがある
            ['2026/10/19 8:00:00', '自社注文出荷実績', '1004', 'B-01', '自社倉庫', 3, 2, 1, 0],
        ]);

        $this->assertSame(['A-01', 'B-01'], array_keys($history->entries));
        $this->assertSame(['own', 'rfc'], array_keys($history->entries['A-01']));
        $this->assertSame(['1001', '1002'], array_map(fn ($entry) => $entry->number, $history->entries['A-01']['own']));
        $this->assertSame(8, $history->entries['A-01']['own'][1]->availableAfter());
        $this->assertSame('2026-10-17 09:00:00', $history->from?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 08:00:00', $history->to?->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Tokyo', $history->from?->getTimezone()->getName());
        $this->assertSame(4, $history->rowCount);
        $this->assertSame(3, $history->skuWarehouseCount());
        $this->assertSame(['API注文引当' => 1, 'RSL在庫増減' => 1, '在庫調整（増）' => 1, '自社注文出荷実績' => 1], $history->typeCounts);
        $this->assertSame([], $history->breaks);
        $this->assertSame([], $history->negatives);
    }

    public function test_reads_cp932_and_utf8_with_or_without_bom(): void
    {
        $rows = [['2026/10/17 09:00:00', '在庫調整（増）', '1001', 'A-01', '自社倉庫', 8, 10, 1, 1]];
        $csv = $this->csv($rows);

        foreach (['cp932' => mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'), 'utf8' => $csv, 'bom' => "\u{FEFF}".$csv] as $encoding => $content) {
            $history = app(BossInventoryHistoryParser::class)->parse(UploadedFile::fake()->createWithContent("{$encoding}.csv", $content));
            $this->assertSame(['在庫調整（増）' => 1], $history->typeCounts, $encoding);
            $this->assertSame(9, $history->entries['A-01']['own'][0]->availableAfter(), $encoding);
        }
    }

    public function test_skips_warehouses_other_than_own_and_rfc(): void
    {
        $history = $this->parse([
            ['2026/10/17 09:00:00', '在庫調整（増）', '1001', 'A-01', '自社倉庫', 8, 10, 1, 1],
            ['2026/10/17 09:00:00', '在庫調整（増）', '1002', 'A-01', '外部倉庫', 0, 3, 0, 0],
        ]);

        $this->assertSame(['own'], array_keys($history->entries['A-01']));
        $this->assertSame(1, $history->rowCount);
    }

    public function test_orders_rows_in_the_same_second_by_how_they_chain_or_else_by_processing_number(): void
    {
        $history = $this->parse([
            // 同じ秒の3行。処理番号の順（2001, 2002, 2003）とは逆に、変動前と変動後で 2003 → 2002 → 2001 とつながる
            ['2026/10/17 09:00:00', 'API注文引当', '2001', 'A-01', '自社倉庫', 10, 10, 2, 3],
            ['2026/10/17 09:00:00', 'API注文引当', '2002', 'A-01', '自社倉庫', 10, 10, 1, 2],
            ['2026/10/17 09:00:00', 'API注文引当', '2003', 'A-01', '自社倉庫', 10, 10, 0, 1],
            // つながりで決まらない同じ秒の2行は、処理番号の順（数字として 9 < 10）
            ['2026/10/18 09:00:00', '在庫調整（増）', '10', 'B-01', 'RFC倉庫', 1, 2, 0, 0],
            ['2026/10/18 09:00:00', '在庫調整（増）', '9', 'B-01', 'RFC倉庫', 5, 6, 0, 0],
        ]);

        $this->assertSame(['2003', '2002', '2001'], array_map(fn ($entry) => $entry->number, $history->entries['A-01']['own']));
        $this->assertSame(['9', '10'], array_map(fn ($entry) => $entry->number, $history->entries['B-01']['rfc']));
    }

    public function test_detects_breaks_in_available_stock_but_not_equal_shifts_of_stock_and_reserved(): void
    {
        $history = $this->parse([
            ['2026/10/17 09:00:00', 'API注文引当', '1001', 'A-01', '自社倉庫', 10, 10, 1, 2],
            // 実在庫と引当済が同じ 1 だけ減っている（出荷が履歴に出ない経路）。実在庫 − 引当済は 8 のままなので切れ目ではない
            ['2026/10/17 10:00:00', 'API注文引当', '1002', 'A-01', '自社倉庫', 9, 9, 1, 2],
            // 実在庫 − 引当済が 7 から 5 に飛んでいる
            ['2026/10/18 09:00:00', '在庫調整（減）', '1003', 'A-01', '自社倉庫', 6, 5, 1, 1],
        ]);

        $this->assertCount(1, $history->breaks);
        $this->assertSame('A-01', $history->breaks[0]['skuCode']);
        $this->assertSame('own', $history->breaks[0]['warehouse']);
        $this->assertSame('2026-10-18 09:00:00', $history->breaks[0]['processedAt']->format('Y-m-d H:i:s'));
        $this->assertSame(7, $history->breaks[0]['expected']);
        $this->assertSame(5, $history->breaks[0]['actual']);
    }

    public function test_detects_negative_available_stock(): void
    {
        $history = $this->parse([
            ['2026/10/17 09:00:00', 'API注文引当', '1001', 'A-01', 'RFC倉庫', 1, 1, 1, 2],
            ['2026/10/17 10:00:00', '在庫調整（増）', '1002', 'B-01', '自社倉庫', 3, 4, 0, 0],
        ]);

        $this->assertCount(1, $history->negatives);
        $this->assertSame(['skuCode' => 'A-01', 'warehouse' => 'rfc', 'rowNumber' => 2], array_diff_key($history->negatives[0], ['processedAt' => true]));
    }

    public function test_rejects_missing_columns_and_invalid_dates_or_quantities_with_the_file_name_and_row(): void
    {
        $this->assertRejected("処理日時,処理種別,SKUコード,倉庫\n", 'history.csvに必須列「処理番号」がありません。');
        $this->assertRejected($this->csv([['2026-10-17 09:00:00', '在庫調整（増）', '1', 'A-01', '自社倉庫', 1, 2, 0, 0]]), 'history.csvの2行目の処理日時を読めません（例: 2026/10/20 09:43:52）。');
        $this->assertRejected($this->csv([['2026/10/17 25:00:00', '在庫調整（増）', '1', 'A-01', '自社倉庫', 1, 2, 0, 0]]), 'history.csvの2行目の処理日時を読めません（例: 2026/10/20 09:43:52）。');
        $this->assertRejected($this->csv([['2026/13/17 09:00:00', '在庫調整（増）', '1', 'A-01', '自社倉庫', 1, 2, 0, 0]]), 'history.csvの2行目の処理日時を読めません（例: 2026/10/20 09:43:52）。');
        $this->assertRejected($this->csv([
            ['2026/10/17 09:00:00', '在庫調整（増）', '1', 'A-01', '自社倉庫', 1, 2, 0, 0],
            ['2026/10/17 10:00:00', '在庫調整（増）', '2', 'A-01', '自社倉庫', 2, '2.5', 0, 0],
        ]), 'history.csvの3行目の数量が整数ではありません。');
        $this->assertRejected(self::HEADER."\n2026/10/17 09:00:00,在庫調整（増）,,,1,A-01,自社倉庫,1,1,2,0,0,1,9\n", 'history.csvの2行目の「変動後実在庫 - 変動後引当済」が、変動後実在庫と変動後引当済の差と合いません。');
    }

    public function test_tells_the_history_apart_from_the_other_import_files(): void
    {
        $parser = app(BossInventoryHistoryParser::class);

        $this->assertTrue($parser->isInventoryHistory($this->file($this->csv([]))));
        $this->assertTrue($parser->isInventoryHistory($this->file(mb_convert_encoding($this->csv([]), 'SJIS-win', 'UTF-8'))));
        $this->assertFalse($parser->isInventoryHistory($this->file("倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-01,6,2\n")));
        $this->assertFalse($parser->isInventoryHistory($this->file("品番,カラーNo,サイズ,キープ残数\nA,01,M,1\n")));
    }

    public function test_the_survey_import_still_rejects_the_history_as_an_unknown_file_type(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post('/api/surveys', ['files' => [UploadedFile::fake()->createWithContent('InventoryHistories.csv', $this->csv([
            ['2026/10/17 09:00:00', '在庫調整（増）', '1001', 'A-01', '自社倉庫', 8, 10, 1, 1],
        ]))]])->assertUnprocessable()
            ->assertJsonPath('errors.files.0', 'InventoryHistories.csvの種類を内容から判定できません。ヘッダーを確認してください。');
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int|string, 6: int|string, 7: int|string, 8: int|string}> $rows 処理日時, 処理種別, 処理番号, SKUコード, 倉庫, 変動前実在庫, 変動後実在庫, 変動前引当済, 変動後引当済 */
    private function csv(array $rows): string
    {
        $lines = [self::HEADER];
        foreach ($rows as [$at, $type, $number, $sku, $warehouse, $stockBefore, $stockAfter, $reservedBefore, $reservedAfter]) {
            $availableBefore = is_numeric($stockBefore) && is_numeric($reservedBefore) ? (int) $stockBefore - (int) $reservedBefore : '';
            $availableAfter = is_numeric($stockAfter) && is_numeric($reservedAfter) && ! str_contains((string) $stockAfter, '.') ? (int) $stockAfter - (int) $reservedAfter : '';
            $lines[] = implode(',', [$at, $type, '楽天市場', 'テストショップ', $number, $sku, $warehouse, 1, $stockBefore, $stockAfter, $reservedBefore, $reservedAfter, $availableBefore, $availableAfter]);
        }

        return implode("\n", $lines)."\n";
    }

    private function file(string $content, string $name = 'history.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int|string, 6: int|string, 7: int|string, 8: int|string}> $rows */
    private function parse(array $rows): BossInventoryHistory
    {
        return app(BossInventoryHistoryParser::class)->parse($this->file($this->csv($rows)));
    }

    private function assertRejected(string $content, string $message): void
    {
        try {
            app(BossInventoryHistoryParser::class)->parse($this->file($content));
            $this->fail('検証エラーになりませんでした: '.$message);
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()['files']);
        }
    }
}
