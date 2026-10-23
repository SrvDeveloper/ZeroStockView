<?php

namespace Tests\Feature;

use App\Exceptions\AmazonInventoryImportException;
use App\Exceptions\AmazonReportException;
use App\Models\AmazonInventoryImport;
use App\Models\AmazonInventorySnapshot;
use App\Models\ImportSetting;
use App\Models\ImportTarget;
use App\Models\Product;
use App\Models\Sku;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Services\AmazonInventoryImportService;
use App\Services\AmazonInventoryReport;
use App\Services\AmazonReportClient;
use App\Services\CrossWalkerClient;
use App\Services\InventoryImportService;
use App\Services\SurveyAmazonInventorySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AmazonInventoryImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_one_snapshot_per_target_sku_after_both_reports_succeed(): void
    {
        $first = $this->target('P-1', 'SKU-1', 'B000000001');
        $this->target('P-2', 'SKU-2', 'B000000002');
        $anotherSetting = ImportSetting::query()->create(['name' => '追加設定']);
        ImportTarget::query()->create(['import_setting_id' => $anotherSetting->id, 'product_id' => $first->id, 'sort_order' => 1]);
        $this->fakeCrossWalker(2);
        $this->fakeReports(
            [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '5'], ['asin' => 'B999999999', 'sku' => 'OTHER', 'quantity' => '99']],
            [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '3'], ['asin' => 'B000000002', 'sku' => 'SKU-2', 'quantity' => '']],
        );

        $service = app(AmazonInventoryImportService::class);
        $queued = $service->queue('manual');
        $this->assertSame('queued', $queued->status);
        $snapshotInserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$snapshotInserts): void {
            $snapshotInserts += preg_match('/^insert into "amazon_inventory_snapshots"/i', $query->sql);
        });
        $run = $service->process($queued->id);

        $this->assertSame('succeeded', $run->status);
        $this->assertSame('manual', $run->trigger_source);
        $this->assertSame('sp_api', $run->source);
        $this->assertNull($run->active_key);
        $this->assertSame(2, $run->target_sku_count);
        $this->assertSame(2, $run->own_row_count);
        $this->assertSame(2, $run->fba_row_count);
        $this->assertSame(1, $run->matched_own_count);
        $this->assertSame(2, $run->matched_fba_count);
        $this->assertSame('2026-10-14', $run->snapshot_date->format('Y-m-d'));
        $this->assertSame('2026-10-14 09:00:00', $run->own_retrieved_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 09:02:00', $run->fba_retrieved_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 09:02:00', $run->basis_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $run->id, 'sku_code' => 'SKU-1', 'amazon_own_stock' => 5, 'amazon_fba_stock' => 3, 'source' => 'sp_api']);
        $this->assertDatabaseHas('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $run->id, 'sku_code' => 'SKU-2', 'amazon_own_stock' => 0, 'amazon_fba_stock' => 0]);
        $this->assertSame(['2026-10-14 09:02:00'], AmazonInventorySnapshot::query()->distinct()->pluck('basis_at')->map->format('Y-m-d H:i:s')->all());
        // SKU ごとに 1 行ずつではなく、まとめて 1 文で保存する
        $this->assertSame(1, $snapshotInserts);
        // 調査のない日だったので、Amazon在庫だけの調査が 1 件作られる（詳細は専用のテスト）。
        $this->assertSame(1, Survey::query()->count());
    }

    public function test_success_creates_an_amazon_only_survey_for_a_day_without_one(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:05:00', 'Asia/Tokyo'));
        $product = $this->target('P-1', 'SKU-1', 'B000000001');
        Sku::factory()->for($product)->create(['sku_code' => 'SKU-NOASIN', 'child_asin' => null, 'sort_order' => 2]);
        $this->fakeCrossWalker(1);
        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '5']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '3']]);

        $service = app(AmazonInventoryImportService::class);
        $import = $service->process($service->queue('scheduled')->id);

        $survey = Survey::query()->sole();
        $this->assertSame('2026-10-14', $survey->survey_date);
        $this->assertSame($import->completed_at->toDateTimeString(), $survey->executed_at->toDateTimeString());
        $this->assertSame(Survey::AMAZON_SOURCE_SP_API, $survey->amazon_source);
        $this->assertFalse($survey->includes_amazon_inventory);
        $this->assertNull($survey->import_setting_name);
        $this->assertSame($import->id, $survey->amazon_inventory_import_id);
        $this->assertSame(0, $survey->files()->count());

        $surveyProduct = $survey->products()->with('skus')->sole();
        $this->assertSame('P-1', $surveyProduct->product_code);
        $this->assertSame([['key' => 'setting:'.ImportTarget::query()->value('import_setting_id'), 'name' => '設定P-1', 'sortOrder' => 1]], $surveyProduct->group_memberships);
        $skus = $surveyProduct->skus->keyBy('sku_code');
        $this->assertSame(5, $skus['SKU-1']->amazon_own_stock);
        $this->assertSame(3, $skus['SKU-1']->amazon_fba_stock);
        $this->assertNull($skus['SKU-NOASIN']->amazon_own_stock);
        foreach ($skus as $sku) {
            $this->assertNull($sku->boss_own_stock);
            $this->assertNull($sku->boss_rfc_stock);
            $this->assertNull($sku->free_stock);
            $this->assertNull($sku->ec_stock);
        }
    }

    public function test_amazon_only_survey_uses_the_basis_time_when_the_import_finishes_after_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 00:10:00', 'Asia/Tokyo'));
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);
        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '5']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '3']]);

        $service = app(AmazonInventoryImportService::class);
        $import = $service->process($service->queue('manual')->id);

        $survey = Survey::query()->sole();
        $this->assertSame('2026-10-14', $survey->survey_date);
        $this->assertSame($import->basis_at->toDateTimeString(), $survey->executed_at->toDateTimeString());
    }

    public function test_success_on_a_day_with_a_survey_updates_it_instead_of_creating_another(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1, 2);
        $sku = $this->surveySku('2026-10-14 08:00:00');

        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '2']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '3']]);
        $service = app(AmazonInventoryImportService::class);
        $service->process($service->queue('manual')->id);
        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '4']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '5']]);
        app(AmazonInventoryImportService::class)->process($service->queue('manual')->id);

        $this->assertSame(1, Survey::query()->count());
        $this->assertSame(4, $sku->fresh()->amazon_own_stock);
        $this->assertSame(5, $sku->fresh()->amazon_fba_stock);
    }

    public function test_failed_import_does_not_create_a_survey(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);
        $reports = Mockery::mock(AmazonReportClient::class);
        $reports->shouldReceive('fetchInventories')->once()->andThrow(new AmazonReportException('FBAレポートの生成に失敗しました。'));
        $this->app->instance(AmazonReportClient::class, $reports);

        $service = app(AmazonInventoryImportService::class);
        $this->assertSame('failed', $service->process($service->queue('manual')->id)->status);
        $this->assertSame(0, Survey::query()->count());
    }

    public function test_survey_creation_failure_keeps_the_import_successful_and_the_command_can_create_it_later(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);
        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '7']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '8']]);
        $importer = Mockery::mock(InventoryImportService::class);
        $importer->shouldReceive('createFromAmazonImport')->once()->andThrow(new \RuntimeException('simulated creation failure'));
        $this->app->instance(InventoryImportService::class, $importer);

        $service = app(AmazonInventoryImportService::class);
        $import = $service->process($service->queue('manual')->id);
        $this->assertSame('succeeded', $import->status);
        $this->assertSame(0, Survey::query()->count());

        $this->app->forgetInstance(InventoryImportService::class);
        $this->artisan('survey:amazon-reconcile', ['date' => '2026-10-14', '--create-survey' => true])
            ->expectsOutput('2026-10-14 のAmazon在庫だけの調査を作成しました。')
            ->assertSuccessful();
        $this->artisan('survey:amazon-reconcile', ['date' => '2026-10-14', '--create-survey' => true])
            ->expectsOutput('2026-10-14 の調査はすでにあるため、作成しませんでした。')
            ->assertSuccessful();

        $survey = Survey::query()->sole();
        $this->assertSame($import->id, $survey->amazon_inventory_import_id);
        $this->assertSame(7, $survey->products()->sole()->skus()->sole()->amazon_own_stock);
    }

    public function test_reconcile_without_the_option_does_not_recreate_a_deleted_amazon_only_survey(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:05:00', 'Asia/Tokyo'));
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);
        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '7']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '8']]);
        $service = app(AmazonInventoryImportService::class);
        $service->process($service->queue('manual')->id);
        Survey::query()->sole()->delete();

        $this->artisan('survey:amazon-reconcile')->assertSuccessful();
        $this->artisan('survey:amazon-reconcile', ['date' => '2026-10-14'])->assertSuccessful();

        $this->assertSame(0, Survey::query()->count());
    }

    public function test_create_survey_option_does_nothing_on_a_day_without_a_successful_import(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');

        $this->artisan('survey:amazon-reconcile', ['date' => '2026-10-14', '--create-survey' => true])
            ->expectsOutput('2026-10-14 は成功したAmazon在庫取込がないため、調査を作成しません。')
            ->assertSuccessful();

        $this->assertSame(0, Survey::query()->count());
    }

    public function test_second_report_failure_keeps_previous_success_history(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1, 2);
        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '5']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '3']]);
        $service = app(AmazonInventoryImportService::class);
        $success = $service->process($service->queue('scheduled')->id);

        $reports = Mockery::mock(AmazonReportClient::class);
        $reports->shouldReceive('fetchInventories')->once()->andThrow(new AmazonReportException('FBAレポートの生成に失敗しました。'));
        $this->app->instance(AmazonReportClient::class, $reports);
        $failed = app(AmazonInventoryImportService::class)->process($service->queue('manual')->id);

        $this->assertSame('failed', $failed->status);
        $this->assertSame('FBAレポートの生成に失敗しました。', $failed->error_message);
        $this->assertSame(0, $failed->snapshots->count());
        $this->assertDatabaseHas('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $success->id, 'amazon_own_stock' => 5, 'amazon_fba_stock' => 3]);
        $this->assertSame(1, AmazonInventorySnapshot::query()->count());
    }

    public function test_rejects_duplicate_target_rows_without_saving_partial_history(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);
        $this->fakeReports(
            [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '2'], ['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '3']],
            [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '4']],
        );

        $run = app(AmazonInventoryImportService::class)->process(app(AmazonInventoryImportService::class)->queue('manual')->id);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('重複', $run->error_message);
        $this->assertSame(0, AmazonInventorySnapshot::query()->count());
    }

    public function test_rejects_negative_quantity(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);

        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '-1']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '1']]);
        $service = app(AmazonInventoryImportService::class);
        $negative = $service->process($service->queue('manual')->id);
        $this->assertSame('failed', $negative->status);
        $this->assertStringContainsString('数量が不正', $negative->error_message);

        $this->assertSame(0, AmazonInventorySnapshot::query()->count());
    }

    public function test_matches_on_asin_when_amazon_seller_sku_uses_a_different_code(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);

        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SELLER-1', 'quantity' => '2']], [['asin' => 'B000000001', 'sku' => 'FBA-1', 'quantity' => '3']]);
        $service = app(AmazonInventoryImportService::class);
        $run = $service->process($service->queue('manual')->id);

        $this->assertSame('succeeded', $run->status);
        $this->assertDatabaseHas('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $run->id, 'sku_code' => 'SKU-1', 'amazon_own_stock' => 2, 'amazon_fba_stock' => 3]);
    }

    public function test_rejects_concurrent_runs_and_recovers_expired_active_run(): void
    {
        $service = app(AmazonInventoryImportService::class);
        $first = $service->queue('manual');

        try {
            $service->queue('scheduled');
            $this->fail('重複起動を拒否すべきです。');
        } catch (AmazonInventoryImportException $exception) {
            $this->assertStringContainsString('すでに実行中', $exception->getMessage());
        }
        $this->assertSame(1, AmazonInventoryImport::query()->count());

        $first->forceFill(['created_at' => now()->subHours(3)])->save();
        $second = $service->queue('scheduled');
        $this->assertSame('failed', $first->fresh()->status);
        $this->assertSame('queued', $second->status);
        $this->assertSame(1, AmazonInventoryImport::query()->where('active_key', 1)->count());
    }

    public function test_same_day_retries_append_new_history(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1, 2);
        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '2']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '3']]);
        $service = app(AmazonInventoryImportService::class);
        $first = $service->process($service->queue('manual')->id);

        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '4']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '5']]);
        $second = app(AmazonInventoryImportService::class)->process($service->queue('manual')->id);

        $this->assertSame('2026-10-14', $first->snapshot_date->format('Y-m-d'));
        $this->assertSame('2026-10-14', $second->snapshot_date->format('Y-m-d'));
        $this->assertSame(2, AmazonInventorySnapshot::query()->count());
        $this->assertDatabaseHas('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $first->id, 'amazon_own_stock' => 2]);
        $this->assertDatabaseHas('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $second->id, 'amazon_own_stock' => 4]);
    }

    public function test_success_updates_all_same_day_surveys_and_failed_import_does_not_clear_them(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1, 3);
        $morning = $this->surveySku('2026-10-14 08:00:00');
        $evening = $this->surveySku('2026-10-14 20:00:00');
        $previousDay = $this->surveySku('2026-10-13 20:00:00');
        $legacy = $this->surveySku('2026-10-14 12:00:00', true);

        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '2']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '3']]);
        $service = app(AmazonInventoryImportService::class);
        $first = $service->process($service->queue('manual')->id);
        $this->assertSame($first->id, $morning->product->survey->fresh()->amazon_inventory_import_id);
        $this->assertSame($first->id, $evening->product->survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(2, $morning->fresh()->amazon_own_stock);
        $this->assertSame(3, $evening->fresh()->amazon_fba_stock);
        $this->assertNull($previousDay->product->survey->fresh()->amazon_inventory_import_id);
        $this->assertNull($legacy->product->survey->fresh()->amazon_inventory_import_id);

        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '4']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '5']]);
        $second = app(AmazonInventoryImportService::class)->process($service->queue('scheduled')->id);
        $this->assertSame($second->id, $morning->product->survey->fresh()->amazon_inventory_import_id);
        $this->assertSame($second->id, $evening->product->survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(4, $morning->fresh()->amazon_own_stock);
        $this->assertSame(5, $evening->fresh()->amazon_fba_stock);

        $reports = Mockery::mock(AmazonReportClient::class);
        $reports->shouldReceive('fetchInventories')->once()->andThrow(new AmazonReportException('FBAレポートの生成に失敗しました。'));
        $this->app->instance(AmazonReportClient::class, $reports);
        $failed = app(AmazonInventoryImportService::class)->process($service->queue('manual')->id);

        $this->assertSame('failed', $failed->status);
        $this->assertSame($second->id, $morning->product->survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(4, $morning->fresh()->amazon_own_stock);
        $this->assertSame(5, $evening->fresh()->amazon_fba_stock);
        $this->assertNull($legacy->product->survey->fresh()->amazon_inventory_import_id);
    }

    public function test_sync_failure_preserves_successful_import_and_manual_retry_repairs_the_survey(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);
        $this->fakeReports([['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '7']], [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '8']]);
        $sku = $this->surveySku('2026-10-14 10:00:00');
        $sync = Mockery::mock(SurveyAmazonInventorySyncService::class);
        $sync->shouldReceive('syncDate')->once()->andThrow(new \RuntimeException('simulated sync failure'));
        $this->app->instance(SurveyAmazonInventorySyncService::class, $sync);

        $service = app(AmazonInventoryImportService::class);
        $import = $service->process($service->queue('manual')->id);

        $this->assertSame('succeeded', $import->status);
        $this->assertSame(1, $import->snapshots->count());
        $this->assertNull($sku->fresh()->amazon_own_stock);
        $this->assertNull($sku->product->survey->fresh()->amazon_inventory_import_id);

        $this->app->forgetInstance(SurveyAmazonInventorySyncService::class);
        $this->artisan('survey:amazon-reconcile', ['date' => '2026-10-14'])->assertSuccessful();
        $this->assertSame($import->id, $sku->product->survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(7, $sku->fresh()->amazon_own_stock);
        $this->assertSame(8, $sku->fresh()->amazon_fba_stock);
    }

    public function test_rejects_report_without_identifiers_before_zero_filling(): void
    {
        $this->target('P-1', 'SKU-1', 'B000000001');
        $this->fakeCrossWalker(1);
        $this->fakeReports(
            [['asin' => '', 'sku' => '', 'quantity' => '']],
            [['asin' => 'B000000001', 'sku' => 'SKU-1', 'quantity' => '1']],
        );

        $service = app(AmazonInventoryImportService::class);
        $run = $service->process($service->queue('manual')->id);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('有効な識別子', $run->error_message);
        $this->assertSame(0, AmazonInventorySnapshot::query()->count());
    }

    public function test_uses_sku_asin_mapping_refreshed_at_import_start(): void
    {
        $this->target('P-1', 'OLD-SKU', 'B000000001');
        $crossWalker = Mockery::mock(CrossWalkerClient::class);
        $crossWalker->shouldReceive('refreshActiveProducts')->once()->andReturnUsing(function (): void {
            $product = Product::query()->where('product_code', 'P-1')->sole();
            $product->skus()->delete();
            Sku::factory()->for($product)->create([
                'sku_code' => 'NEW-SKU',
                'child_asin' => 'B000000009',
                'sort_order' => 1,
            ]);
        });
        $this->app->instance(CrossWalkerClient::class, $crossWalker);
        $this->fakeReports(
            [['asin' => 'B000000009', 'sku' => 'NEW-SKU', 'quantity' => '7']],
            [['asin' => 'B000000009', 'sku' => 'NEW-SKU', 'quantity' => '8']],
        );

        $service = app(AmazonInventoryImportService::class);
        $run = $service->process($service->queue('manual')->id);

        $this->assertSame('succeeded', $run->status);
        $this->assertDatabaseHas('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $run->id, 'sku_code' => 'NEW-SKU', 'asin' => 'B000000009', 'amazon_own_stock' => 7]);
        $this->assertDatabaseMissing('amazon_inventory_snapshots', ['sku_code' => 'OLD-SKU']);
    }

    public function test_skips_active_skus_without_asin_without_storing_false_zero_stock(): void
    {
        $product = $this->target('P-1', 'SKU-LISTED', 'B000000001');
        Sku::factory()->for($product)->create(['sku_code' => 'SKU-UNLISTED', 'child_asin' => null, 'sort_order' => 2]);
        $this->fakeCrossWalker(1);
        $this->fakeReports(
            [['asin' => 'B000000001', 'sku' => 'SKU-LISTED', 'quantity' => '5']],
            [['asin' => 'B000000001', 'sku' => 'SKU-LISTED', 'quantity' => '3']],
        );

        $service = app(AmazonInventoryImportService::class);
        $run = $service->process($service->queue('manual')->id);

        $this->assertSame('succeeded', $run->status);
        $this->assertSame(1, $run->target_sku_count);
        $this->assertDatabaseHas('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $run->id, 'sku_code' => 'SKU-LISTED', 'amazon_own_stock' => 5]);
        $this->assertDatabaseMissing('amazon_inventory_snapshots', ['amazon_inventory_import_id' => $run->id, 'sku_code' => 'SKU-UNLISTED']);
    }

    public function test_fails_before_fetching_reports_if_no_active_sku_has_an_asin(): void
    {
        $product = $this->target('P-1', 'SKU-UNLISTED', 'B000000001');
        $product->skus()->update(['child_asin' => null]);
        $this->fakeCrossWalker(1);
        $reports = Mockery::mock(AmazonReportClient::class);
        $reports->shouldNotReceive('fetchInventories');
        $this->app->instance(AmazonReportClient::class, $reports);

        $service = app(AmazonInventoryImportService::class);
        $run = $service->process($service->queue('manual')->id);

        $this->assertSame('failed', $run->status);
        $this->assertSame('ASINが設定された有効な取込対象SKUがありません。', $run->error_message);
        $this->assertSame(0, AmazonInventorySnapshot::query()->count());
    }

    private function target(string $productCode, string $skuCode, string $asin): Product
    {
        $product = Product::factory()->create(['product_code' => $productCode]);
        Sku::factory()->for($product)->create(['sku_code' => $skuCode, 'child_asin' => $asin, 'sort_order' => 1]);
        $setting = ImportSetting::query()->create(['name' => '設定'.$productCode]);
        ImportTarget::query()->create(['import_setting_id' => $setting->id, 'product_id' => $product->id, 'sort_order' => 1]);

        return $product;
    }

    private function surveySku(string $executedAt, bool $legacy = false): SurveySku
    {
        $survey = Survey::factory()->create([
            'executed_at' => $executedAt,
            'amazon_source' => $legacy ? Survey::AMAZON_SOURCE_LEGACY_FILE : Survey::AMAZON_SOURCE_SP_API,
            'includes_amazon_inventory' => $legacy,
        ]);
        $product = SurveyProduct::factory()->for($survey)->create();

        return SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => 'SKU-1',
            'amazon_own_stock' => $legacy ? 11 : null,
            'amazon_fba_stock' => $legacy ? 12 : null,
        ]);
    }

    private function fakeCrossWalker(int $targetCount, int $times = 1): void
    {
        $client = Mockery::mock(CrossWalkerClient::class);
        // 同期は行わず、手元の品番（有効な品番）がそのまま CrossWalker の有効な全品番だとみなす
        $client->shouldReceive('refreshActiveProducts')->times($times)->andReturnUsing(fn () => $this->assertCount($targetCount, Product::surveyTargets()));
        $this->app->instance(CrossWalkerClient::class, $client);
    }

    /**
     * @param  list<array{asin: string, sku: string, quantity: string}>  $ownRows
     * @param  list<array{asin: string, sku: string, quantity: string}>  $fbaRows
     */
    private function fakeReports(array $ownRows, array $fbaRows): void
    {
        $client = Mockery::mock(AmazonReportClient::class);
        $client->shouldReceive('fetchInventories')->once()->andReturn([$this->report('OWN', $ownRows), $this->report('FBA', $fbaRows, '2026-10-14 09:02:00')]);
        $this->app->instance(AmazonReportClient::class, $client);
    }

    /** @param list<array{asin: string, sku: string, quantity: string}> $rows */
    private function report(string $type, array $rows, string $retrievedAt = '2026-10-14 09:00:00'): AmazonInventoryReport
    {
        return new AmazonInventoryReport($type, 'report-'.$type, CarbonImmutable::parse($retrievedAt, 'Asia/Tokyo'), $rows);
    }
}
