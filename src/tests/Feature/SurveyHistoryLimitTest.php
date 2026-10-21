<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyProduct;
use App\Services\InventoryImportService;
use App\Services\SurveyHistoryPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 調査履歴は最大500件を残し、それを超えたら調査日の古い調査から、取込ファイルとメモごと削除する。
 */
class SurveyHistoryLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-21 12:00:00');
        config([
            'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1',
            'services.crosswalker.api_key' => 'test-api-key',
        ]);
        Http::fake(fn (ClientRequest $request) => Http::response(['data' => in_array('A', $request['item_nos'] ?? [], true) ? [[
            'item_no' => 'A', 'brand' => 'BRAND', 'category' => 'CATEGORY', 'parent_asin' => null, 'status' => 'active',
            'skus' => [['sku_code' => 'A-1', 'child_asin' => 'BA1', 'status' => 'active', 'tq_item_no' => 'TA', 'tq_color_no' => '01', 'tq_size' => 'M']],
            'updated_at' => '2026-10-01T00:00:00.000000Z',
        ]] : []]));
        $setting = ImportSetting::query()->create(['name' => 'G']);
        $product = Product::query()->create(['product_code' => 'A', 'brand' => '', 'category' => '', 'status' => 'active', 'synced_at' => now()]);
        ImportTarget::query()->create(['import_setting_id' => $setting->id, 'product_id' => $product->id, 'sort_order' => 1]);
    }

    public function test_pruner_keeps_the_newest_five_hundred_surveys_and_deletes_older_ones_with_their_files(): void
    {
        $this->pastSurveys(SurveyHistoryPruner::MAX_SURVEYS + 2);
        $oldest = Survey::query()->orderBy('executed_at')->firstOrFail();
        $product = SurveyProduct::factory()->for($oldest)->create(['memo' => '古い調査のメモ']);
        Storage::disk('local')->put('survey-imports/old.csv', 'old');
        $file = SurveyFile::factory()->for($oldest)->create(['disk' => 'local', 'stored_path' => 'survey-imports/old.csv']);
        $secondOldestId = Survey::query()->orderBy('executed_at')->skip(1)->value('id');

        $this->assertSame(2, app(SurveyHistoryPruner::class)->prune());

        $this->assertSame(SurveyHistoryPruner::MAX_SURVEYS, Survey::query()->count());
        $this->assertNull(Survey::query()->find($oldest->id));
        $this->assertNull(Survey::query()->find($secondOldestId));
        $this->assertNull(SurveyProduct::query()->find($product->id));
        $this->assertNull(SurveyFile::query()->find($file->id));
        Storage::disk('local')->assertMissing('survey-imports/old.csv');
        // もう一度実行しても、それ以上は消さない
        $this->assertSame(0, app(SurveyHistoryPruner::class)->prune());
    }

    public function test_pruner_does_nothing_up_to_five_hundred_surveys(): void
    {
        $this->pastSurveys(SurveyHistoryPruner::MAX_SURVEYS);

        $this->assertSame(0, app(SurveyHistoryPruner::class)->prune());
        $this->assertSame(SurveyHistoryPruner::MAX_SURVEYS, Survey::query()->count());
    }

    public function test_saving_the_501st_survey_by_file_import_deletes_the_oldest(): void
    {
        $this->pastSurveys(SurveyHistoryPruner::MAX_SURVEYS);
        $oldestId = Survey::query()->orderBy('executed_at')->value('id');

        $survey = app(InventoryImportService::class)->import(
            [UploadedFile::fake()->createWithContent('倉庫毎の在庫数レポート.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1,1,0\n")],
            ImportSetting::query()->sole(),
        );

        $this->assertSame(SurveyHistoryPruner::MAX_SURVEYS, Survey::query()->count());
        $this->assertNull(Survey::query()->find($oldestId));
        $this->assertNotNull(Survey::query()->find($survey->id));

        // 同じ日の追加取込は調査を作らないので、削除しない
        $nextOldestId = Survey::query()->orderBy('executed_at')->value('id');
        app(InventoryImportService::class)->import(
            [UploadedFile::fake()->createWithContent('倉庫毎の在庫数レポート.csv', "倉庫,SKUコード,実在庫 (倉庫毎),引当済 (倉庫毎)\n自社倉庫,A-1,2,0\n")],
            ImportSetting::query()->sole(),
        );
        $this->assertNotNull(Survey::query()->find($nextOldestId));
    }

    public function test_saving_the_501st_survey_from_an_amazon_import_deletes_the_oldest(): void
    {
        $this->pastSurveys(SurveyHistoryPruner::MAX_SURVEYS);
        $oldestId = Survey::query()->orderBy('executed_at')->value('id');
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded', 'trigger_source' => 'manual',
            'basis_at' => now(), 'snapshot_date' => now()->toDateString(), 'completed_at' => now(),
        ]);
        $import->snapshots()->create(['product_code' => 'A', 'sku_code' => 'A-1', 'asin' => 'BA1', 'amazon_own_stock' => 1, 'amazon_fba_stock' => 2, 'basis_at' => now()]);

        $this->assertNotNull(app(InventoryImportService::class)->createFromAmazonImport($import));

        $this->assertSame(SurveyHistoryPruner::MAX_SURVEYS, Survey::query()->count());
        $this->assertNull(Survey::query()->find($oldestId));
    }

    /** 昨日から1日ずつさかのぼった日の調査を作る */
    private function pastSurveys(int $count): void
    {
        $rows = [];
        for ($day = 1; $day <= $count; $day++) {
            $executedAt = now()->subDays($day)->setTime(9, 0);
            $rows[] = [
                'executed_at' => $executedAt, 'survey_date' => $executedAt->toDateString(),
                'includes_amazon_inventory' => false, 'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
                'created_at' => $executedAt, 'updated_at' => $executedAt,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('surveys')->insert($chunk);
        }
    }
}
