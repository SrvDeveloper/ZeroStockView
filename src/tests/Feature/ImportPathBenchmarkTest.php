<?php

namespace Tests\Feature;

use App\Models\ImportSetting;
use App\Models\Survey;
use App\Models\User;
use App\Services\AmazonInventoryImportService;
use App\Services\AmazonInventoryReport;
use App\Services\AmazonReportClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * 取込経路の計測（docs/性能改善 PERF-05）。本番と同じ規模（112品番・817 SKU、ASINあり559 SKU）のデータで、
 * 各経路の所要時間・SQLの発行数（種類別）・トランザクションの長さ・PHPのピークメモリを測って表にする。
 *
 * 通常のテストでは飛ばす。計測するとき: RUN_BENCHMARKS=1 php artisan test tests/Feature/ImportPathBenchmarkTest.php
 * テスト用のDB（インメモリSQLite）を使い、設定先のDBには触れない。外部API（CrossWalker・Amazon）は偽の応答を返す。
 */
class ImportPathBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_COUNT = 112;

    private const SKU_COUNT = 817;

    private const ASIN_SKU_COUNT = 559;

    /** @var array<string, array<string, mixed>> CrossWalkerの品番データ（品番コード => 品番） */
    private array $items = [];

    /** @var array<string, int|float>|null 計測中の値 */
    private ?array $current = null;

    /** @var array<string, int> 計測中の、表ごと・種類ごとのSQLの数（例: "update skus"） */
    private array $byTable = [];

    private int $baseTransactionLevel = 0;

    private ?float $transactionStartedAt = null;

    /** @var list<array<string, int|float|string>> */
    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('RUN_BENCHMARKS')) {
            $this->markTestSkipped('取込経路の計測は RUN_BENCHMARKS=1 のときだけ実行する。');
        }

        Storage::fake('local');
        config([
            'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1',
            'services.crosswalker.api_key' => 'test-api-key',
        ]);
        $this->items = $this->buildItems();
        Http::fake(fn (ClientRequest $request) => Http::response([
            'data' => array_values(array_intersect_key($this->items, array_flip($request['item_nos'] ?? []))),
        ]));
        $this->listenToDatabase();
    }

    public function test_measure_import_paths(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'Asia/Tokyo'));
        $codes = array_keys($this->items);

        // 品番グループ: 50品番・50品番・12品番（ほかのグループと重複する品番を含む）
        $this->measure('品番グループの保存（新規・50品番）', fn () => $this->postJson('/api/import-settings', ['name' => 'G1', 'product_codes' => array_slice($codes, 0, 50)])->assertSuccessful());
        $this->postJson('/api/import-settings', ['name' => 'G2', 'product_codes' => array_slice($codes, 50, 50)])->assertCreated();
        $this->postJson('/api/import-settings', ['name' => 'G3', 'product_codes' => [...array_slice($codes, 100, 12), $codes[0], $codes[1]]])->assertCreated();
        $setting = ImportSetting::query()->where('name', 'G1')->firstOrFail();
        $this->measure('品番グループの保存（変更なしで保存し直す・50品番）', fn () => $this->putJson("/api/import-settings/{$setting->id}", ['name' => 'G1', 'product_codes' => array_slice($codes, 0, 50)])->assertOk());

        // 毎朝9:00のAmazon在庫取込（その日の調査がないので、Amazon在庫だけの調査を作る）
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Asia/Tokyo'));
        $this->fakeAmazonReports();
        $amazon = app(AmazonInventoryImportService::class);
        $this->measure('Amazon在庫取込（調査を新規作成）', fn () => $this->assertSame('succeeded', $amazon->process($amazon->queue('scheduled')->id)->status));

        // ファイル取込（同じ日の調査を更新し、Amazon在庫を反映する）
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Tokyo'));
        $this->measure('取込（3ファイル、Amazon在庫あり）', fn () => $this->post('/api/surveys', ['files' => [$this->bossFile(), $this->keepFile(), $this->freeFile()]])->assertSuccessful());
        $this->measure('同日の追加取込（1ファイル）', fn () => $this->post('/api/surveys', ['files' => [$this->freeFile()]])->assertSuccessful());
        $survey = Survey::query()->firstOrFail();
        $this->measure('再照合', fn () => $this->postJson("/api/surveys/{$survey->id}/rerun", ['import_setting_id' => $setting->id])->assertSuccessful());

        // Amazon在庫の取り直し（既存の調査に反映する）
        $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00:00', 'Asia/Tokyo'));
        $this->fakeAmazonReports(offset: 1);
        $amazon = app(AmazonInventoryImportService::class); // 差し替えたレポートを使うよう、作り直す
        $this->measure('Amazon在庫の取り直し（既存の調査に反映）', fn () => $this->assertSame('succeeded', $amazon->process($amazon->queue('manual')->id)->status));
        // 計測した経路が実際に反映まで済んでいることを確かめる（反映の失敗はログに残るだけなので）
        $latestImportId = DB::table('amazon_inventory_imports')->max('id');
        $this->assertSame($latestImportId, $survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(1, DB::table('survey_skus')->where('sku_code', 'P-0001-01')->value('amazon_own_stock'));

        // 翌日、Amazon在庫の取込より先にファイルを取り込む（調査を新規作成）
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:30:00', 'Asia/Tokyo'));
        $this->measure('取込（3ファイル、調査を新規作成・Amazon在庫なし）', fn () => $this->post('/api/surveys', ['files' => [$this->bossFile(), $this->keepFile(), $this->freeFile()]])->assertSuccessful());

        $this->assertSame(self::SKU_COUNT, DB::table('skus')->count());
        $this->report();
    }

    /** @param callable(): mixed $run */
    private function measure(string $label, callable $run): void
    {
        $this->current = ['select' => 0, 'insert' => 0, 'update' => 0, 'delete' => 0, 'other' => 0, 'queryMs' => 0.0, 'transactionMs' => 0.0];
        $this->byTable = [];
        $this->baseTransactionLevel = DB::transactionLevel();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage();
        $startedAt = hrtime(true);

        $run();

        $elapsedMs = (hrtime(true) - $startedAt) / 1e6;
        $this->results[] = [
            'label' => $label,
            'ms' => round($elapsedMs),
            'queries' => $this->current['select'] + $this->current['insert'] + $this->current['update'] + $this->current['delete'] + $this->current['other'],
            'select' => $this->current['select'],
            'insert' => $this->current['insert'],
            'update' => $this->current['update'],
            'delete' => $this->current['delete'],
            'queryMs' => round($this->current['queryMs']),
            'transactionMs' => round($this->current['transactionMs']),
            'peakMb' => round((memory_get_peak_usage() - $memoryBefore) / 1048576, 1),
            'byTable' => collect($this->byTable)->sortDesc()->filter(fn (int $count): bool => $count >= 10)->map(fn (int $count, string $key): string => "{$key} {$count}")->implode('、'),
        ];
        $this->current = null;
    }

    private function listenToDatabase(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if ($this->current === null) {
                return;
            }
            $type = strtolower(strtok(ltrim($query->sql), ' ') ?: '');
            $this->current[in_array($type, ['select', 'insert', 'update', 'delete'], true) ? $type : 'other']++;
            $this->current['queryMs'] += $query->time;
            if (in_array($type, ['insert', 'update', 'delete'], true) && preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+["`]?(\w+)/i', $query->sql, $match) === 1) {
                $key = "{$type} {$match[1]}";
                $this->byTable[$key] = ($this->byTable[$key] ?? 0) + 1;
            }
        });
        // RefreshDatabase がテストを1つのトランザクションで包むので、計測開始時の深さから1段深い間をアプリのトランザクションとして数える
        Event::listen(TransactionBeginning::class, function (): void {
            if ($this->current !== null && DB::transactionLevel() === $this->baseTransactionLevel + 1) {
                $this->transactionStartedAt = hrtime(true);
            }
        });
        $finish = function (): void {
            if ($this->current !== null && $this->transactionStartedAt !== null && DB::transactionLevel() === $this->baseTransactionLevel) {
                $this->current['transactionMs'] += (hrtime(true) - $this->transactionStartedAt) / 1e6;
                $this->transactionStartedAt = null;
            }
        };
        Event::listen(TransactionCommitted::class, $finish);
        Event::listen(TransactionRolledBack::class, $finish);
    }

    private function report(): void
    {
        $lines = [
            '| 経路 | 所要時間 | SQL | SELECT | INSERT | UPDATE | DELETE | SQLの時間 | トランザクション | ピークメモリ |',
            '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
        ];
        foreach ($this->results as $row) {
            $lines[] = "| {$row['label']} | {$row['ms']} ms | {$row['queries']} | {$row['select']} | {$row['insert']} | {$row['update']} | {$row['delete']} | {$row['queryMs']} ms | {$row['transactionMs']} ms | +{$row['peakMb']} MB |";
        }
        $lines[] = '';
        $lines[] = '| 経路 | 書き込みの内訳（10回以上のもの） |';
        $lines[] = '| --- | --- |';
        foreach ($this->results as $row) {
            $lines[] = "| {$row['label']} | {$row['byTable']} |";
        }
        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);
        if (is_string($path = env('BENCHMARK_OUTPUT')) && $path !== '') {
            file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        }
    }

    /** @return array<string, array<string, mixed>> 112品番・817 SKU（先頭の33品番は8 SKU、残りは7 SKU）。ASINは先頭から559 SKUに付ける */
    private function buildItems(): array
    {
        $items = [];
        $skuIndex = 0;
        for ($productIndex = 0; $productIndex < self::PRODUCT_COUNT; $productIndex++) {
            $code = sprintf('P-%04d', $productIndex + 1);
            $skus = [];
            $skuCount = $productIndex < self::SKU_COUNT - self::PRODUCT_COUNT * 7 ? 8 : 7;
            for ($i = 0; $i < $skuCount; $i++) {
                $skus[] = [
                    'sku_code' => sprintf('%s-%02d', $code, $i + 1),
                    'child_asin' => $skuIndex < self::ASIN_SKU_COUNT ? sprintf('B%09d', $skuIndex + 1) : null,
                    'status' => 'active',
                    'tq_item_no' => sprintf('T%04d', $productIndex + 1),
                    'tq_color_no' => sprintf('%02d', intdiv($i, 4) + 1),
                    'tq_size' => ['S', 'M', 'L', 'XL'][$i % 4],
                ];
                $skuIndex++;
            }
            $items[$code] = [
                'item_no' => $code,
                'brand' => 'BRAND',
                'category' => 'CATEGORY',
                'parent_asin' => sprintf('BP%08d', $productIndex + 1),
                'status' => 'active',
                'skus' => $skus,
                'updated_at' => '2026-10-01T00:00:00.000000Z',
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function allSkus(): array
    {
        return array_merge(...array_map(fn (array $item): array => $item['skus'], array_values($this->items)));
    }

    /** 倉庫毎の在庫数レポート。対象SKUの自社倉庫・RFC倉庫の行に、対象外の行を足して約3,000行にする */
    private function bossFile(): UploadedFile
    {
        $rows = [['倉庫毎の実在庫', '商品ID', '商品名', 'カテゴリー名', '実在庫数', '倉庫ID', '倉庫', '実在庫 (倉庫毎)', '引当済 (倉庫毎)', 'SKUコード']];
        foreach ($this->allSkus() as $index => $sku) {
            foreach (['自社倉庫', 'RFC倉庫'] as $warehouse) {
                $rows[] = ['', (string) $index, '商品', 'カテゴリ', '10', '1', $warehouse, (string) ($index % 7), '0', $sku['sku_code']];
            }
        }
        for ($i = count($rows); $i < 3025; $i++) {
            $rows[] = ['', (string) $i, '対象外', 'カテゴリ', '1', '1', '自社倉庫', '1', '0', sprintf('OTHER-%05d', $i)];
        }

        return $this->csv('boss.csv', $rows);
    }

    /** KEEP一覧表（約420行、対象SKUの一部とそれ以外） */
    private function keepFile(): UploadedFile
    {
        $rows = [['会社コード', 'キープ伝票番号', '得意先コード', '品番', 'カラーNo', 'サイズ', '商品名', 'キープ残数']];
        foreach (array_slice($this->allSkus(), 0, 300) as $index => $sku) {
            $rows[] = ['1', (string) $index, '59', $sku['tq_item_no'], $sku['tq_color_no'], $sku['tq_size'], '商品', (string) ($index % 3)];
        }
        for ($i = count($rows); $i < 417; $i++) {
            $rows[] = ['1', (string) $i, '59', sprintf('X%04d', $i), '01', 'M', '対象外', '1'];
        }

        return $this->csv('keep.csv', $rows);
    }

    /** 在庫一覧照会表（約3,500行、対象SKUの全行とそれ以外） */
    private function freeFile(): UploadedFile
    {
        $rows = [['ブランド', '品番', 'カラーNo', 'ｻｲｽﾞ', '品名', 'カラー名', '倉庫ｺｰﾄﾞ', '倉庫名', '可能在庫数', '出荷残数', 'ｷｰﾌﾟ残数', '現在庫数']];
        foreach ($this->allSkus() as $index => $sku) {
            $rows[] = ['BRAND', $sku['tq_item_no'], $sku['tq_color_no'], $sku['tq_size'], '商品', '黒', '01', '本社', (string) ($index % 5), '0', '0', '5'];
        }
        for ($i = count($rows); $i < 3490; $i++) {
            $rows[] = ['BRAND', sprintf('Y%05d', $i), '01', 'M', '対象外', '黒', '01', '本社', '1', '0', '0', '1'];
        }

        return $this->csv('free.csv', $rows);
    }

    /** @param list<list<string>> $rows 実ファイルと同じくShift_JIS（CP932）で書く */
    private function csv(string $name, array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, eol: "\r\n");
        }
        rewind($stream);
        $contents = mb_convert_encoding((string) stream_get_contents($stream), 'SJIS-win', 'UTF-8');
        fclose($stream);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    /** 自社在庫・FBA在庫のレポート。ASINのある559 SKUに、レポートにしかない行を足す */
    private function fakeAmazonReports(int $offset = 0): void
    {
        $rows = [];
        foreach ($this->allSkus() as $index => $sku) {
            if ($sku['child_asin'] !== null) {
                $rows[] = ['asin' => $sku['child_asin'], 'sku' => $sku['sku_code'], 'quantity' => (string) (($index + $offset) % 4)];
            }
        }
        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['asin' => sprintf('BX%08d', $i), 'sku' => sprintf('AMZ-ONLY-%03d', $i), 'quantity' => '1'];
        }
        $retrievedAt = CarbonImmutable::now('Asia/Tokyo');
        $reports = Mockery::mock(AmazonReportClient::class);
        $reports->shouldReceive('fetchOwnInventory')->andReturn(new AmazonInventoryReport('OWN', 'own', $retrievedAt, $rows));
        $reports->shouldReceive('fetchFbaInventory')->andReturn(new AmazonInventoryReport('FBA', 'fba', $retrievedAt, $rows));
        $this->app->instance(AmazonReportClient::class, $reports);
    }
}
