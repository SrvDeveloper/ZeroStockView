<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\BossInventoryHistory;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 在庫変動履歴の確認と適用の API（docs/BOSS在庫の休日補完 BHR-04）。データはすべて架空。
 * 金曜（10/16）と月曜（10/19）に BOSS のレポートを取り込み、土日（10/17・10/18）を補完する。
 */
class BossInventoryHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = '処理日時,処理種別,処理番号,SKUコード,倉庫,変動前実在庫,変動後実在庫,変動前引当済,変動後引当済';

    /** 金曜 0:10 〜 月曜 10:00 の履歴。金曜のレポートは 7:00 の入荷のあと、月曜のレポートは 10:00 の引当の前に出力した想定 */
    private const ROWS = [
        ['2026/10/16 0:10:00', '在庫調整（増）', '1', 'A-1', '自社倉庫', 9, 10, 0, 0],
        ['2026/10/16 07:00:00', '在庫調整（増）', '2', 'A-1', '自社倉庫', 10, 12, 0, 0],
        ['2026/10/17 15:00:00', 'API注文引当', '3', 'A-1', '自社倉庫', 12, 12, 0, 1],
        ['2026/10/18 12:00:00', 'API注文引当', '4', 'A-2', '自社倉庫', 5, 5, 0, 1],
        // B-1 は金曜のレポート（3）と履歴（8）が合わない
        ['2026/10/17 10:00:00', 'API注文引当', '5', 'B-1', '自社倉庫', 8, 8, 0, 1],
        ['2026/10/19 10:00:00', 'API注文引当', '6', 'A-2', 'RFC倉庫', 3, 3, 0, 1],
    ];

    /** SKUコード => [自社倉庫, RFC倉庫] */
    private const FRIDAY = ['A-1' => [12, 2], 'A-2' => [5, 3], 'B-1' => [3, 1]];

    private const MONDAY = ['A-1' => [11, 2], 'A-2' => [4, 3], 'B-1' => [7, 1]];

    private const PRODUCTS = ['A' => ['A-1', 'A-2'], 'B' => ['B-1']];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-19 12:00:00');
        foreach (self::PRODUCTS as $productCode => $skuCodes) {
            $product = Product::factory()->create(['product_code' => $productCode, 'status' => 'active']);
            foreach ($skuCodes as $index => $skuCode) {
                Sku::factory()->for($product)->create(['sku_code' => $skuCode, 'child_asin' => 'BASIN'.str_replace('-', '', $skuCode), 'sort_order' => $index + 1]);
            }
        }
    }

    public function test_preview_returns_the_plan_without_writing(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $friday = $this->survey('2026-10-16', self::FRIDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        $saturday = $this->survey('2026-10-17', [], ['executed_at' => '2026-10-17 09:05:00']);
        $this->survey('2026-10-19', self::MONDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);

        $this->postJson('/api/boss-inventory-histories/preview', ['file' => $this->historyFile()])
            ->assertOk()
            ->assertJsonPath('data.periodFrom', '2026-10-16T00:10:00+09:00')
            ->assertJsonPath('data.periodTo', '2026-10-19T10:00:00+09:00')
            ->assertJsonPath('data.rowCount', 6)
            ->assertJsonPath('data.typeCounts', ['API注文引当' => 4, '在庫調整（増）' => 2])
            ->assertJsonPath('data.baseSurveys', [
                ['date' => '2026-10-16', 'surveyId' => (string) $friday->id, 'estimatedReportAt' => '2026-10-16T07:00:00+09:00', 'mismatchCount' => 1, 'unusableReason' => null],
                ['date' => '2026-10-19', 'surveyId' => (string) Survey::query()->where('survey_date', '2026-10-19')->value('id'), 'estimatedReportAt' => '2026-10-19T00:00:00+09:00', 'mismatchCount' => 0, 'unusableReason' => null],
            ])
            ->assertJsonPath('data.days', [
                ['date' => '2026-10-17', 'basisAt' => '2026-10-17T09:00:00+09:00', 'surveyExists' => true, 'surveyId' => (string) $saturday->id, 'filledSkuCount' => 2, 'nullSkuCount' => 1, 'nullReasons' => ['mismatch' => 1]],
                ['date' => '2026-10-18', 'basisAt' => '2026-10-18T09:00:00+09:00', 'surveyExists' => false, 'surveyId' => null, 'filledSkuCount' => 2, 'nullSkuCount' => 1, 'nullReasons' => ['mismatch' => 1]],
            ])
            ->assertJsonPath('data.unfillableDates', [])
            ->assertJsonPath('data.mismatches', [['skuCode' => 'B-1', 'warehouse' => '自社倉庫', 'reason' => 'mismatch']])
            ->assertJsonPath('data.mismatchCount', 1);

        $this->assertDatabaseCount('boss_inventory_histories', 0);
        $this->assertSame(3, Survey::query()->count());
        $this->assertNull($saturday->fresh()->boss_source);
        $this->assertNull($this->stock($saturday, 'A-1')->boss_own_stock);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_apply_fills_days_with_and_without_a_survey_and_marks_them_as_history(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EDITOR]);
        $this->actingAs($user);
        $this->survey('2026-10-16', self::FRIDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        $saturday = $this->survey('2026-10-17', [], ['executed_at' => '2026-10-17 09:05:00']);
        $this->survey('2026-10-19', self::MONDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        // 日曜は調査がなく、Amazon 在庫の取得結果だけがある
        $this->amazonImport('2026-10-18', 'A-1', 4, 5);

        $response = $this->postJson('/api/boss-inventory-histories', ['file' => $this->historyFile()])
            ->assertCreated()
            ->assertJsonPath('data.surveys.*.executedAt', [$saturday->executed_at->toISOString(), '2026-10-18T00:00:00.000000Z'])
            ->assertJsonPath('data.summary.days.*.date', ['2026-10-17', '2026-10-18']);

        $history = BossInventoryHistory::query()->sole();
        $this->assertSame('InventoryHistories_FromTo_20261019100500.csv', $history->original_name);
        $this->assertSame($user->id, $history->uploaded_by);
        $this->assertSame(6, $history->row_count);
        $this->assertSame($response->json('data.summary'), $history->summary);
        $this->assertSame('2026-11-18 12:00:00', $history->expires_at->format('Y-m-d H:i:s'));
        Storage::disk('local')->assertExists($history->stored_path);

        $saturday->refresh();
        $this->assertSame(Survey::BOSS_SOURCE_HISTORY, $saturday->boss_source);
        $this->assertSame('2026-10-17 09:00:00', $saturday->boss_basis_at->format('Y-m-d H:i:s'));
        $this->assertSame($history->id, $saturday->boss_inventory_history_id);
        $this->assertSame([12, 2], $this->bossStocks($saturday, 'A-1'));
        $this->assertSame([5, 3], $this->bossStocks($saturday, 'A-2'));
        // 照合で合わない倉庫は NULL。期間内に行のない RFC倉庫は基準の調査の値
        $this->assertSame([null, 1], $this->bossStocks($saturday, 'B-1'));

        $sunday = Survey::query()->where('survey_date', '2026-10-18')->sole();
        $this->assertSame(Survey::BOSS_SOURCE_HISTORY, $sunday->boss_source);
        $this->assertSame('2026-10-18 09:00:00', $sunday->executed_at->format('Y-m-d H:i:s'));
        $this->assertSame(Survey::AMAZON_SOURCE_SP_API, $sunday->amazon_source);
        $this->assertSame($history->id, $sunday->boss_inventory_history_id);
        $this->assertSame([11, 2], $this->bossStocks($sunday, 'A-1'));
        $this->assertSame([5, 3], $this->bossStocks($sunday, 'A-2'));
        $this->assertSame([null, 1], $this->bossStocks($sunday, 'B-1'));
        $sku = $this->stock($sunday, 'A-1');
        $this->assertSame([4, 5, null, null], [$sku->amazon_own_stock, $sku->amazon_fba_stock, $sku->free_stock, $sku->ec_stock]);
        $this->assertSame(AmazonInventoryImport::query()->value('id'), $sunday->amazon_inventory_import_id);
    }

    public function test_created_survey_without_an_amazon_import_leaves_amazon_pending(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $this->survey('2026-10-16', self::FRIDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        $this->survey('2026-10-19', self::MONDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);

        $this->postJson('/api/boss-inventory-histories', ['file' => $this->historyFile()])->assertCreated()
            ->assertJsonPath('data.surveys.0.amazonInventoryStatus', 'pending');

        $saturday = Survey::query()->where('survey_date', '2026-10-17')->sole();
        $this->assertNull($saturday->amazon_inventory_import_id);
        $sku = $this->stock($saturday, 'A-1');
        $this->assertSame([12, 2, null, null], [$sku->boss_own_stock, $sku->boss_rfc_stock, $sku->amazon_own_stock, $sku->amazon_fba_stock]);
    }

    public function test_apply_keeps_report_and_legacy_days_and_redoes_previous_history_days(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $friday = $this->survey('2026-10-16', self::FRIDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        $previous = BossInventoryHistory::factory()->create();
        $saturday = $this->survey('2026-10-17', ['A-1' => [1, 1], 'A-2' => [1, 1], 'B-1' => [1, 1]], [
            'boss_source' => Survey::BOSS_SOURCE_HISTORY, 'boss_basis_at' => '2026-10-17 09:00:00', 'boss_inventory_history_id' => $previous->id,
        ]);
        $sunday = $this->survey('2026-10-18', ['A-1' => [99, 99]], [
            'survey_date' => null, 'amazon_source' => Survey::AMAZON_SOURCE_LEGACY_FILE, 'includes_amazon_inventory' => true,
        ]);
        $monday = $this->survey('2026-10-19', self::MONDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);

        $this->postJson('/api/boss-inventory-histories', ['file' => $this->historyFile()])->assertCreated()
            ->assertJsonPath('data.summary.days.*.date', ['2026-10-17'])
            ->assertJsonPath('data.summary.unfillableDates', [['date' => '2026-10-18', 'reason' => '旧方式の調査（Amazon在庫をファイルで取り込んだ調査）のため、補完しません。']]);

        $saturday->refresh();
        $this->assertNotSame($previous->id, $saturday->boss_inventory_history_id);
        $this->assertSame([12, 2], $this->bossStocks($saturday, 'A-1'));
        $this->assertSame([null, 1], $this->bossStocks($saturday, 'B-1'));
        $this->assertModelExists($previous);
        $this->assertSame([99, 99], $this->bossStocks($sunday, 'A-1'));
        $this->assertNull($sunday->fresh()->boss_source);
        $this->assertSame(1, Survey::query()->whereDate('executed_at', '2026-10-18')->count());
        foreach ([[$friday, self::FRIDAY], [$monday, self::MONDAY]] as [$report, $stocks]) {
            $this->assertSame(Survey::BOSS_SOURCE_REPORT, $report->fresh()->boss_source);
            $this->assertSame($stocks['A-1'], $this->bossStocks($report, 'A-1'));
        }
    }

    public function test_history_without_a_report_day_in_the_period_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $this->survey('2026-10-15', self::FRIDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        $this->survey('2026-10-16', self::FRIDAY);

        foreach (['/api/boss-inventory-histories/preview', '/api/boss-inventory-histories'] as $url) {
            $this->postJson($url, ['file' => $this->historyFile()])
                ->assertUnprocessable()
                ->assertJsonPath('errors.file', ['在庫変動履歴の期間に、BOSSのレポートを取り込んだ日が含まれていません。']);
        }
        $this->assertDatabaseCount('boss_inventory_histories', 0);
        $this->assertSame(2, Survey::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_history_starting_after_every_report_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        // 金曜のレポートは 7:00 の入荷の前に出力したが、履歴は 7:00 の行から始まる（レポートの出力が履歴の始まりより前と判定される）
        $this->survey('2026-10-16', ['A-1' => [10, 2], 'A-2' => [5, 3], 'B-1' => [8, 1]], ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        $rows = [
            ['2026/10/16 07:00:00', '在庫調整（増）', '2', 'A-1', '自社倉庫', 10, 12, 0, 0],
            ['2026/10/18 12:00:00', 'API注文引当', '4', 'A-2', '自社倉庫', 5, 5, 0, 1],
        ];

        $this->postJson('/api/boss-inventory-histories/preview', ['file' => $this->historyFile($rows)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file', ['在庫変動履歴が、BOSSのレポートを出力した時刻より後から始まっています。レポートを出力した日の0:00から出力してください。']);
    }

    public function test_history_without_a_day_to_fill_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        foreach (['2026-10-16' => self::FRIDAY, '2026-10-17' => self::FRIDAY, '2026-10-18' => self::MONDAY, '2026-10-19' => self::MONDAY] as $date => $stocks) {
            $this->survey($date, $stocks, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        }

        $this->postJson('/api/boss-inventory-histories', ['file' => $this->historyFile()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file', ['補完する日がありません。']);
        $this->assertDatabaseCount('boss_inventory_histories', 0);
    }

    public function test_other_files_are_rejected_on_the_file_field(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));

        $this->postJson('/api/boss-inventory-histories/preview', ['file' => UploadedFile::fake()->createWithContent('倉庫毎の在庫数レポート.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1,5,1\n")])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file', ['BOSSの在庫変動履歴のファイルではありません。BOSSから出力した在庫変動履歴のCSVを選択してください。']);
        $this->postJson('/api/boss-inventory-histories/preview', [])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_viewers_are_forbidden_and_guests_are_unauthorized(): void
    {
        $this->postJson('/api/boss-inventory-histories/preview', ['file' => $this->historyFile()])->assertUnauthorized();
        $this->postJson('/api/boss-inventory-histories', ['file' => $this->historyFile()])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]));
        $this->postJson('/api/boss-inventory-histories/preview', ['file' => $this->historyFile()])->assertForbidden();
        $this->postJson('/api/boss-inventory-histories', ['file' => $this->historyFile()])->assertForbidden();
        $this->assertDatabaseCount('boss_inventory_histories', 0);
    }

    public function test_inventory_trends_and_the_daily_csv_return_the_filled_boss_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $this->survey('2026-10-16', self::FRIDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        $this->survey('2026-10-19', self::MONDAY, ['boss_source' => Survey::BOSS_SOURCE_REPORT]);
        $this->amazonImport('2026-10-18', 'A-1', 4, 5);
        $this->postJson('/api/boss-inventory-histories', ['file' => $this->historyFile()])->assertCreated();

        // A-1 の BOSS 在庫（自社倉庫と RFC倉庫の合計）。土日は再現した値
        $this->getJson('/api/inventory-trends?product_code=A&from=2026-10-16&to=2026-10-19&scope=boss')
            ->assertOk()
            ->assertJsonPath('data.series.0.points.*.quantity', [14, 14, 13, 13]);

        $contents = file_get_contents($this->get('/api/inventory-trends/export?product_code=A&from=2026-10-18&to=2026-10-18')->baseResponse->getFile()->getPathname());
        $this->assertIsString($contents);
        $this->assertStringContainsString('A-1,', $contents);
        $this->assertStringContainsString(',4,5,11,2,,', $contents);
    }

    /**
     * その日の調査（Amazon 在庫は SP-API）。品番 A・B の SKU を持ち、BOSS 在庫は指定がなければ NULL。
     *
     * @param  array<string, array{0: int|null, 1: int|null}>  $bossStocks
     * @param  array<string, mixed>  $attributes
     */
    private function survey(string $date, array $bossStocks, array $attributes = []): Survey
    {
        $survey = Survey::factory()->spApi()->create(['executed_at' => "{$date} 09:30:00", 'survey_date' => $date, ...$attributes]);
        $productOrder = 0;
        foreach (self::PRODUCTS as $productCode => $skuCodes) {
            $product = SurveyProduct::factory()->for($survey)->create(['product_code' => $productCode, 'sort_order' => ++$productOrder]);
            foreach ($skuCodes as $index => $skuCode) {
                SurveySku::factory()->for($product, 'product')->create([
                    'sku_code' => $skuCode,
                    'sort_order' => $index + 1,
                    'boss_own_stock' => $bossStocks[$skuCode][0] ?? null,
                    'boss_rfc_stock' => $bossStocks[$skuCode][1] ?? null,
                ]);
            }
        }

        return $survey;
    }

    private function amazonImport(string $date, string $skuCode, int $own, int $fba): void
    {
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'basis_at' => "{$date} 09:00:00",
            'snapshot_date' => $date,
            'completed_at' => "{$date} 09:10:00",
        ]);
        $import->snapshots()->create([
            'product_code' => 'A',
            'sku_code' => $skuCode,
            'asin' => 'BASIN'.str_replace('-', '', $skuCode),
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
            'basis_at' => "{$date} 09:00:00",
        ]);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int}>|null $rows */
    private function historyFile(?array $rows = null): UploadedFile
    {
        $lines = array_map(fn (array $row): string => implode(',', $row), $rows ?? self::ROWS);

        return UploadedFile::fake()->createWithContent('InventoryHistories_FromTo_20261019100500.csv', self::HEADER."\n".implode("\n", $lines)."\n");
    }

    private function stock(Survey $survey, string $skuCode): SurveySku
    {
        return SurveySku::query()->whereHas('product', fn ($query) => $query->where('survey_id', $survey->id))->where('sku_code', $skuCode)->sole();
    }

    /** @return array{0: int|null, 1: int|null} */
    private function bossStocks(Survey $survey, string $skuCode): array
    {
        $sku = $this->stock($survey, $skuCode);

        return [$sku->boss_own_stock, $sku->boss_rfc_stock];
    }
}
