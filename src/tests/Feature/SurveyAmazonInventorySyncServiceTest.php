<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Services\SurveyAmazonInventorySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SurveyAmazonInventorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_survey_clears_a_previous_day_link_without_touching_legacy_history(): void
    {
        $previous = $this->import('2026-10-14', '2026-10-14 10:00:00');
        $survey = $this->survey('2026-10-15 00:30:00', Survey::AMAZON_SOURCE_SP_API, $previous->id);
        $sku = $this->sku($survey, 'SKU-1', 0, 0);
        $legacy = $this->survey('2026-10-15 09:00:00', Survey::AMAZON_SOURCE_LEGACY_FILE);
        $legacySku = $this->sku($legacy, 'LEGACY-1', 7, 8);
        $unresolved = Survey::factory()->create(['executed_at' => '2026-10-15 09:00:00']);
        $unresolvedSku = $this->sku($unresolved, 'UNRESOLVED-1', 9, 10);
        $conflicting = Survey::factory()->legacyFile()->create([
            'executed_at' => '2026-10-15 09:00:00',
            'amazon_source' => Survey::AMAZON_SOURCE_SP_API,
        ]);
        $conflictingSku = $this->sku($conflicting, 'CONFLICT-1', 11, 12);

        $this->assertNull(app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id));
        $this->assertNull($survey->fresh()->amazon_inventory_import_id);
        $this->assertNull($sku->fresh()->amazon_own_stock);
        $this->assertNull($sku->fresh()->amazon_fba_stock);

        $this->assertSame(1, app(SurveyAmazonInventorySyncService::class)->syncDate('2026-10-15'));
        $this->assertSame(7, $legacySku->fresh()->amazon_own_stock);
        $this->assertSame(8, $legacySku->fresh()->amazon_fba_stock);
        $this->assertSame(9, $unresolvedSku->fresh()->amazon_own_stock);
        $this->assertSame(10, $unresolvedSku->fresh()->amazon_fba_stock);
        $this->assertSame(11, $conflictingSku->fresh()->amazon_own_stock);
        $this->assertSame(12, $conflictingSku->fresh()->amazon_fba_stock);
    }

    public function test_latest_success_updates_all_same_day_surveys_and_unmatched_skus_stay_null(): void
    {
        $first = $this->import('2026-10-15', '2026-10-15 09:00:00');
        $this->snapshot($first, 'SKU-1', 2, 3);
        $latest = $this->import('2026-10-15', '2026-10-15 10:00:00');
        $this->snapshot($latest, 'SKU-1', 0, 6);
        $this->import('2026-10-15', '2026-10-15 11:00:00', 'failed');
        $previous = $this->survey('2026-10-14 23:59:00', Survey::AMAZON_SOURCE_SP_API);
        $previousSku = $this->sku($previous, 'PREVIOUS', 12, 13);

        $morning = $this->survey('2026-10-15 08:00:00', Survey::AMAZON_SOURCE_SP_API);
        $morningMatch = $this->sku($morning, 'SKU-1', null, null);
        $morningMatch->update(['child_asin' => null]);
        $morningMissing = $this->sku($morning, 'SKU-NO-ASIN', 0, 0);
        $evening = $this->survey('2026-10-15 20:00:00', Survey::AMAZON_SOURCE_SP_API);
        $eveningMatch = $this->sku($evening, 'SKU-1', 99, 99);

        $service = app(SurveyAmazonInventorySyncService::class);
        $this->assertSame(2, $service->syncDate($latest->snapshot_date));
        $this->assertSame($latest->id, $morning->fresh()->amazon_inventory_import_id);
        $this->assertSame($latest->id, $evening->fresh()->amazon_inventory_import_id);
        $this->assertSame(0, $morningMatch->fresh()->amazon_own_stock);
        $this->assertSame(6, $morningMatch->fresh()->amazon_fba_stock);
        $this->assertSame('B000000001', $morningMatch->fresh()->child_asin);
        $this->assertNull($morningMissing->fresh()->amazon_own_stock);
        $this->assertNull($morningMissing->fresh()->amazon_fba_stock);
        $this->assertSame(0, $eveningMatch->fresh()->amazon_own_stock);
        $this->assertSame(6, $eveningMatch->fresh()->amazon_fba_stock);
        $this->assertSame(12, $previousSku->fresh()->amazon_own_stock);
        $this->assertSame(13, $previousSku->fresh()->amazon_fba_stock);

        $updatedAt = $morningMatch->fresh()->updated_at;
        $this->travel(5)->minutes();
        $this->assertSame(2, $service->syncDate('2026-10-15'));
        $this->assertTrue($updatedAt->equalTo($morningMatch->fresh()->updated_at));
    }

    public function test_sync_restores_missing_survey_asin_from_matching_import_snapshot(): void
    {
        $import = $this->import('2026-10-15', '2026-10-15 10:00:00');
        $this->snapshot($import, 'FLH-001PH-1-10', 0, 25);
        $survey = $this->survey('2026-10-15 11:00:00', Survey::AMAZON_SOURCE_SP_API, $import->id);
        $sku = $this->sku($survey, 'FLH-001PH-1-10', 0, 25);
        $sku->update(['child_asin' => null]);

        $this->assertSame($import->id, app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id));
        $this->assertSame('B000000001', $sku->fresh()->child_asin);
        $this->assertSame(25, $sku->fresh()->amazon_fba_stock);
    }

    public function test_pending_retry_applies_latest_success_once_and_never_rolls_back_to_an_older_import(): void
    {
        $old = $this->import('2026-10-15', '2026-10-15 09:00:00');
        $this->snapshot($old, 'SKU-1', 2, 3);
        $latest = $this->import('2026-10-15', '2026-10-15 10:00:00');
        $this->snapshot($latest, 'SKU-1', 8, 9);
        $survey = $this->survey('2026-10-15 08:00:00', Survey::AMAZON_SOURCE_SP_API, $old->id);
        $sku = $this->sku($survey, 'SKU-1', 2, 3);
        $service = app(SurveyAmazonInventorySyncService::class);

        $this->assertSame(1, $service->syncPendingDate('2026-10-15'));
        $this->assertSame($latest->id, $survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(8, $sku->fresh()->amazon_own_stock);
        $this->assertSame(0, $service->syncPendingDate('2026-10-15'));
        $this->assertSame(1, $service->syncDate('2026-10-15'));
        $this->assertSame($latest->id, $survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(8, $sku->fresh()->amazon_own_stock);
    }

    public function test_latest_success_is_selected_by_basis_completion_then_id(): void
    {
        $day = '2026-10-15';
        $basis = '2026-10-15 10:00:00';
        $this->snapshot($this->import($day, $basis, 'succeeded', '2026-10-15 10:10:00'), 'SKU-1', 1, 0);
        $this->snapshot($this->import($day, $basis, 'succeeded', '2026-10-15 10:20:00'), 'SKU-1', 2, 0);
        $latest = $this->import($day, $basis, 'succeeded', '2026-10-15 10:20:00');
        $this->snapshot($latest, 'SKU-1', 3, 0);
        $survey = $this->survey('2026-10-15 23:59:00', Survey::AMAZON_SOURCE_SP_API);
        $sku = $this->sku($survey, 'SKU-1', null, null);

        $this->assertSame($latest->id, app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id));
        $this->assertSame(3, $sku->fresh()->amazon_own_stock);
    }

    public function test_replaying_an_older_sync_cannot_restore_the_older_quantity(): void
    {
        $first = $this->import('2026-10-15', '2026-10-15 09:00:00');
        $this->snapshot($first, 'SKU-1', 2, 3);
        $survey = $this->survey('2026-10-15 08:00:00', Survey::AMAZON_SOURCE_SP_API);
        $sku = $this->sku($survey, 'SKU-1', null, null);
        $service = app(SurveyAmazonInventorySyncService::class);

        $this->assertSame($first->id, $service->syncSurvey($survey->id));
        $this->assertSame(2, $sku->fresh()->amazon_own_stock);

        $latest = $this->import('2026-10-15', '2026-10-15 10:00:00');
        $this->snapshot($latest, 'SKU-1', 8, 9);
        $this->assertSame(1, $service->syncDate('2026-10-15'));
        $this->assertSame($latest->id, $service->syncSurvey($survey->id));
        $this->assertSame($latest->id, $survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(8, $sku->fresh()->amazon_own_stock);
        $this->assertSame(9, $sku->fresh()->amazon_fba_stock);
    }

    public function test_incomplete_success_does_not_partially_update_a_survey(): void
    {
        $import = $this->import('2026-10-15', '2026-10-15 10:00:00');
        $survey = $this->survey('2026-10-15 09:00:00', Survey::AMAZON_SOURCE_SP_API);
        $sku = $this->sku($survey, 'SKU-1', 4, 5);

        try {
            app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id);
            $this->fail('SKU別スナップショットの欠落を検出する必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('スナップショット', $exception->getMessage());
        }

        $this->assertNull($survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(4, $sku->fresh()->amazon_own_stock);
        $this->assertSame(5, $sku->fresh()->amazon_fba_stock);
    }

    public function test_success_without_completion_time_does_not_overwrite_a_survey(): void
    {
        $import = AmazonInventoryImport::query()->create([
            'status' => 'succeeded',
            'trigger_source' => 'manual',
            'snapshot_date' => '2026-10-15',
            'basis_at' => '2026-10-15 10:00:00',
            'completed_at' => null,
        ]);
        $this->snapshot($import, 'SKU-1', 8, 9);
        $survey = $this->survey('2026-10-15 09:00:00', Survey::AMAZON_SOURCE_SP_API);
        $sku = $this->sku($survey, 'SKU-1', 4, 5);

        try {
            app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id);
            $this->fail('不完全な成功履歴を適用してはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('完了日時', $exception->getMessage());
        }

        $this->assertSame(4, $sku->fresh()->amazon_own_stock);
        $this->assertNull($survey->fresh()->amazon_inventory_import_id);
    }

    public function test_database_failure_rolls_back_sku_and_import_pointer_together(): void
    {
        $import = $this->import('2026-10-15', '2026-10-15 10:00:00');
        $this->snapshot($import, 'SKU-1', 8, 9);
        $survey = $this->survey('2026-10-15 09:00:00', Survey::AMAZON_SOURCE_SP_API);
        $sku = $this->sku($survey, 'SKU-1', 4, 5);
        $failed = false;
        DB::listen(function ($query) use (&$failed): void {
            if (! $failed && str_starts_with(strtolower(ltrim($query->sql)), 'update') && str_contains($query->sql, 'survey_skus')) {
                $failed = true;
                throw new \RuntimeException('simulated database interruption');
            }
        });

        try {
            app(SurveyAmazonInventorySyncService::class)->syncSurvey($survey->id);
            $this->fail('途中のDBエラーが伝播する必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated database interruption', $exception->getMessage());
        }

        $this->assertTrue($failed);
        $this->assertNull($survey->fresh()->amazon_inventory_import_id);
        $this->assertSame(4, $sku->fresh()->amazon_own_stock);
        $this->assertSame(5, $sku->fresh()->amazon_fba_stock);
    }

    private function survey(string $executedAt, string $source, ?int $importId = null): Survey
    {
        return Survey::factory()->create([
            'executed_at' => $executedAt,
            'includes_amazon_inventory' => $source === Survey::AMAZON_SOURCE_LEGACY_FILE,
            'amazon_source' => $source,
            'amazon_inventory_import_id' => $importId,
        ]);
    }

    private function sku(Survey $survey, string $code, ?int $own, ?int $fba): SurveySku
    {
        $product = SurveyProduct::factory()->for($survey)->create();

        return SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => $code,
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
        ]);
    }

    private function import(string $day, string $basis, string $status = 'succeeded', ?string $completed = null): AmazonInventoryImport
    {
        return AmazonInventoryImport::query()->create([
            'status' => $status,
            'trigger_source' => 'manual',
            'snapshot_date' => $day,
            'basis_at' => CarbonImmutable::parse($basis, 'Asia/Tokyo'),
            'completed_at' => CarbonImmutable::parse($completed ?? $basis, 'Asia/Tokyo'),
        ]);
    }

    private function snapshot(AmazonInventoryImport $import, string $skuCode, int $own, int $fba): void
    {
        $import->snapshots()->create([
            'product_code' => 'PRODUCT-1',
            'sku_code' => $skuCode,
            'asin' => 'B000000001',
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
            'basis_at' => $import->basis_at,
        ]);
    }
}
