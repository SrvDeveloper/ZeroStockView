<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\Survey;
use App\Models\SurveySku;
use App\Services\InventoryImportService;
use App\Services\SurveyAmazonInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Amazon在庫のスナップショットは、調査にAmazon在庫を写すためだけに使い、取込の対象日から30日を過ぎたら消す。
 * 取込ファイル（アップロードから30日）の保存期限が先に切れ、再照合で保存期限は延びない。
 */
class AmazonInventorySnapshotRetentionTest extends TestCase
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
        Http::fake(fn (ClientRequest $request) => Http::response(['data' => in_array('A', $request['item_nos'] ?? [], true) ? [[
            'item_no' => 'A', 'brand' => 'BRAND', 'category' => 'CATEGORY', 'parent_asin' => null, 'status' => 'active',
            'skus' => [
                ['sku_code' => 'A-1', 'child_asin' => 'BA1', 'status' => 'active', 'tq_item_no' => 'TA', 'tq_color_no' => '01', 'tq_size' => 'M'],
                ['sku_code' => 'A-2', 'child_asin' => 'BA2', 'status' => 'active', 'tq_item_no' => 'TA', 'tq_color_no' => '01', 'tq_size' => 'L'],
            ],
            'updated_at' => '2026-10-01T00:00:00.000000Z',
        ]] : []]));
        $setting = ImportSetting::query()->create(['name' => 'G']);
        $product = Product::query()->create(['product_code' => 'A', 'brand' => '', 'category' => '', 'status' => 'active', 'synced_at' => now()]);
        ImportTarget::query()->create(['import_setting_id' => $setting->id, 'product_id' => $product->id, 'sort_order' => 1]);
    }

    public function test_prune_deletes_only_snapshots_older_than_thirty_days_and_keeps_the_import_records(): void
    {
        $this->travelTo('2026-11-20 01:00:00');
        $old = $this->amazonImport('2026-10-20');
        $kept = $this->amazonImport('2026-10-21');

        $this->artisan('amazon-inventory:prune-snapshots')
            ->expectsOutput('2件の保存期間を過ぎたスナップショットを削除しました。')
            ->assertSuccessful();

        $this->assertSame(0, $old->snapshots()->count());
        $this->assertSame(2, $kept->snapshots()->count());
        $this->assertSame(2, AmazonInventoryImport::query()->count());
        $this->assertTrue($old->snapshotsExpired());
        $this->assertFalse($kept->snapshotsExpired());
    }

    public function test_survey_files_expire_before_the_snapshots_of_their_day(): void
    {
        $this->travelTo('2026-10-20 23:59:00');
        $amazon = $this->amazonImport('2026-10-20');
        $survey = $this->import(['A-1' => 1]);
        $expiresAt = $survey->files()->sole()->expires_at;

        // 日の終わりにアップロードしたファイルでも、期限が切れた時点ではスナップショットはまだ残る
        $this->travelTo($expiresAt);
        $this->artisan('survey-files:prune')->assertSuccessful();
        $this->artisan('amazon-inventory:prune-snapshots')->assertSuccessful();
        $this->assertSame(0, $survey->files()->count());
        $this->assertSame(2, $amazon->snapshots()->count());

        $this->travelTo('2026-11-20 00:00:00');
        $this->artisan('amazon-inventory:prune-snapshots')->assertSuccessful();
        $this->assertSame(0, $amazon->snapshots()->count());
    }

    public function test_rerun_keeps_the_upload_time_and_expiry_of_the_original_files(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $survey = $this->import(['A-1' => 1]);
        $original = $survey->files()->sole();

        $this->travelTo('2026-11-10 12:00:00');
        $rerun = app(InventoryImportService::class)->reimport($survey, ImportSetting::query()->sole());

        $file = $rerun->files()->sole();
        $this->assertNotSame($original->stored_path, $file->stored_path);
        $this->assertSame('2026-10-20 12:00:00', $file->uploaded_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-19 12:00:00', $file->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_surveys_keep_their_amazon_values_once_the_days_snapshots_are_pruned(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $amazon = $this->amazonImport('2026-10-20');
        $survey = $this->import(['A-1' => 1, 'A-2' => 2]);
        $this->assertSame([[3, 4], [3, 4]], $this->amazonStocks());

        $this->travelTo('2026-11-25 12:00:00');
        $this->artisan('amazon-inventory:prune-snapshots')->assertSuccessful();

        // 写し直しの処理は、エラーにせず調査の値をそのまま残す
        $this->assertSame($amazon->id, app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id));
        $this->artisan('survey:amazon-reconcile 2026-10-20')->assertSuccessful();
        $this->assertSame([[3, 4], [3, 4]], $this->amazonStocks());

        // ファイルが残っていれば作り直せる（ファイルを延命した場合を想定）。Amazon の値は残し、ほかの在庫だけ作り直す
        $survey->files()->update(['expires_at' => now()->addDay()]);
        app(InventoryImportService::class)->reimport($survey->refresh(), ImportSetting::query()->sole());
        $this->assertSame([[3, 4], [3, 4]], $this->amazonStocks());
        $this->assertSame([1, 2], SurveySku::query()->orderBy('sku_code')->pluck('boss_own_stock')->all());
        $this->assertSame($amazon->id, $survey->refresh()->amazon_inventory_import_id);
    }

    public function test_no_amazon_only_survey_is_created_from_an_import_whose_snapshots_are_pruned(): void
    {
        $this->travelTo('2026-11-25 12:00:00');
        $this->amazonImport('2026-10-20');
        $this->artisan('amazon-inventory:prune-snapshots')->assertSuccessful();

        $this->artisan('survey:amazon-reconcile 2026-10-20 --create-survey')
            ->expectsOutput('2026-10-20 はAmazon在庫の記録が保存期間を過ぎて削除されているため、調査を作成しません。')
            ->assertSuccessful();
        $this->assertNull(app(InventoryImportService::class)->createFromAmazonImport(AmazonInventoryImport::query()->sole()));
        $this->assertSame(0, Survey::query()->count());
    }

    public function test_a_successful_import_within_the_retention_period_without_snapshots_is_still_an_error(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $survey = $this->import(['A-1' => 1]);
        $this->amazonImport('2026-10-20')->snapshots()->delete();

        $this->expectException(RuntimeException::class);
        app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id);
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

    /** その日の成功した取込（A-1・A-2 とも自社 3・FBA 4） */
    private function amazonImport(string $date): AmazonInventoryImport
    {
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded', 'trigger_source' => 'manual',
            'basis_at' => "{$date} 09:00:00", 'snapshot_date' => $date, 'completed_at' => "{$date} 09:00:00",
        ]);
        foreach (['A-1' => 'BA1', 'A-2' => 'BA2'] as $skuCode => $asin) {
            $import->snapshots()->create([
                'product_code' => 'A', 'sku_code' => $skuCode, 'asin' => $asin,
                'amazon_own_stock' => 3, 'amazon_fba_stock' => 4, 'basis_at' => "{$date} 09:00:00",
            ]);
        }

        return $import;
    }

    /** @return list<array{0: int|null, 1: int|null}> SKUコード順の [自社, FBA] */
    private function amazonStocks(): array
    {
        return SurveySku::query()->orderBy('sku_code')->get()
            ->map(fn (SurveySku $sku): array => [$sku->amazon_own_stock, $sku->amazon_fba_stock])->all();
    }
}
