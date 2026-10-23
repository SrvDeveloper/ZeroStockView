<?php

namespace Tests\Feature;

use App\Models\BossInventoryHistory;
use App\Models\Survey;
use App\Models\SurveySku;
use App\Services\InventoryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 在庫変動履歴の取込記録と、調査の BOSS の取得方式の保存（docs/BOSS在庫の休日補完 BHR-03） */
class BossInventoryHistoryStorageTest extends TestCase
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
        Http::fake(fn (ClientRequest $request) => Http::response(['data' => $request->method() === 'GET' ? [[
            'item_no' => 'A', 'brand' => 'BRAND', 'category' => 'CATEGORY', 'parent_asin' => null, 'status' => 'active',
            'skus' => [['sku_code' => 'A-1', 'child_asin' => 'BA1', 'status' => 'active', 'tq_item_no' => 'TA', 'tq_color_no' => '01', 'tq_size' => 'M']],
            'updated_at' => '2026-10-01T00:00:00.000000Z',
        ]] : []]));
    }

    public function test_importing_a_boss_report_records_report_as_the_boss_source(): void
    {
        $this->travelTo('2026-10-17 10:00:00');
        $history = BossInventoryHistory::factory()->create();
        Survey::factory()->spApi()->create([
            'executed_at' => '2026-10-17 09:00:00', 'survey_date' => '2026-10-17',
            'boss_source' => Survey::BOSS_SOURCE_HISTORY, 'boss_basis_at' => '2026-10-17 09:00:00', 'boss_inventory_history_id' => $history->id,
        ]);

        $survey = app(InventoryImportService::class)->import([
            UploadedFile::fake()->createWithContent('倉庫毎の在庫数レポート.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1,5,1\n"),
        ]);

        // 在庫変動履歴から再現した日でも、レポートを取り込めばレポートの値と印にする
        $this->assertSame(Survey::BOSS_SOURCE_REPORT, $survey->boss_source);
        $this->assertNull($survey->boss_basis_at);
        $this->assertNull($survey->boss_inventory_history_id);
        $this->assertSame(4, SurveySku::query()->where('sku_code', 'A-1')->sole()->boss_own_stock);
    }

    public function test_importing_without_a_boss_report_leaves_the_boss_source_unset(): void
    {
        $this->travelTo('2026-10-17 10:00:00');

        $survey = app(InventoryImportService::class)->import([
            UploadedFile::fake()->createWithContent('KEEP一覧表.csv', "品番,カラーNo,サイズ,キープ残数\nTA,01,M,2\n"),
        ]);

        $this->assertNull($survey->fresh()->boss_source);
    }

    public function test_prune_deletes_expired_history_files_and_keeps_the_records(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        Storage::disk('local')->put('boss-inventory-histories/old.csv', 'old');
        Storage::disk('local')->put('boss-inventory-histories/new.csv', 'new');
        $expired = BossInventoryHistory::factory()->create(['stored_path' => 'boss-inventory-histories/old.csv', 'expires_at' => '2026-10-20 11:00:00']);
        $kept = BossInventoryHistory::factory()->create(['stored_path' => 'boss-inventory-histories/new.csv', 'expires_at' => '2026-10-21 11:00:00']);
        $survey = Survey::factory()->history()->create(['boss_inventory_history_id' => $expired->id]);

        $this->artisan('survey-files:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing('boss-inventory-histories/old.csv');
        Storage::disk('local')->assertExists('boss-inventory-histories/new.csv');
        $this->assertNull($expired->fresh()->stored_path);
        $this->assertNull($expired->fresh()->disk);
        $this->assertSame($expired->original_name, $expired->fresh()->original_name);
        $this->assertSame('boss-inventory-histories/new.csv', $kept->fresh()->stored_path);
        // どの日をどの履歴で補完したかは残る
        $this->assertSame($expired->id, $survey->fresh()->boss_inventory_history_id);
    }

    public function test_history_factory_state_records_nine_oclock_of_the_survey_day(): void
    {
        $survey = Survey::factory()->history()->create(['executed_at' => '2026-10-18 09:00:00']);

        $this->assertSame(Survey::BOSS_SOURCE_HISTORY, $survey->boss_source);
        $this->assertSame('2026-10-18 09:00:00', $survey->boss_basis_at?->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(BossInventoryHistory::class, $survey->bossInventoryHistory);
    }
}
