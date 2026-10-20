<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use App\Services\BulkRowWriter;
use App\Services\SurveyAmazonInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillSurveyAmazonInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_apply_and_rerun_only_change_certain_three_file_history(): void
    {
        $previous = $this->import('2026-10-14', '2026-10-14 10:00:00');
        $this->snapshot($previous, 'SKU-M', 9, 9);
        $first = $this->import('2026-10-15', '2026-10-15 09:00:00');
        $this->snapshot($first, 'SKU-M', 2, 3);
        $latest = $this->import('2026-10-15', '2026-10-15 10:00:00');
        $this->snapshot($latest, 'SKU-M', 0, 5);
        $failed = $this->import('2026-10-15', '2026-10-15 11:00:00', 'failed');
        $this->snapshot($failed, 'SKU-M', 99, 99);

        $three = $this->survey('2026-10-15 08:00:00', false, $previous->id);
        $matched = $this->sku($three, 'SKU-M', 0, 0);
        $missing = $this->sku($three, 'SKU-MISSING', 0, 0);
        $this->file($three, '倉庫毎の在庫数レポート');
        $this->file($three, 'KEEP一覧表');
        $this->file($three, '在庫一覧照会表');
        $pending = $this->survey('2026-10-16 08:00:00', false, $previous->id);
        $pendingSku = $this->sku($pending, 'SKU-PENDING', 0, 0);

        $already = $this->survey('2026-10-15 12:00:00', false, $latest->id, Survey::AMAZON_SOURCE_SP_API);
        $alreadySku = $this->sku($already, 'SKU-M', 0, 5);
        $legacy = $this->survey('2026-10-15 09:00:00', true, null);
        $legacySku = $this->sku($legacy, 'LEGACY', 7, 8, '保存メモ');
        $legacyFile = $this->file($legacy, '在庫商品レポート');
        $ambiguous = $this->survey('2026-10-15 09:00:00', true, null);
        $ambiguousSku = $this->sku($ambiguous, 'UNKNOWN', 0, 0);

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $dry = $this->runBackfill();
        $this->assertSame('dry-run', $dry['mode']);
        $this->assertSame(['legacy_file' => 1, 'three_file' => 3, 'ambiguous' => 1], array_intersect_key($dry['summary'], array_flip(['legacy_file', 'three_file', 'ambiguous'])));
        $this->assertSame(2, $dry['summary']['surveysToChange']);
        $this->assertSame(3, $dry['summary']['skusToChange']);
        $this->assertSame(['pending' => 1, 'partial' => 1, 'complete' => 1], array_intersect_key($dry['summary'], array_flip(['pending', 'partial', 'complete'])));
        $rows = collect($dry['surveys'])->keyBy('surveyId');
        $this->assertSame($latest->id, $rows[$three->id]['latestSuccessfulImportId']);
        $this->assertSame(1, $rows[$three->id]['expected']['nullSkuCount']);
        $this->assertSame(0, $rows[$three->id]['expected']['ownTotal']);
        $this->assertSame(5, $rows[$three->id]['expected']['fbaTotal']);
        $this->assertSame('ambiguous', $rows[$ambiguous->id]['classification']);
        $legacyFingerprint = $rows[$legacy->id]['protectedFingerprint'];
        $this->assertNotNull($legacyFingerprint);
        $this->assertSame([], $writes);
        $this->assertSame(0, $matched->fresh()->amazon_fba_stock);

        $applied = $this->runBackfill(['--apply' => true]);
        $this->assertSame('apply', $applied['mode']);
        $this->assertSame(2, $applied['summary']['surveysToChange']);
        $this->assertSame(Survey::AMAZON_SOURCE_SP_API, $three->fresh()->amazon_source);
        $this->assertSame($latest->id, $three->fresh()->amazon_inventory_import_id);
        $this->assertSame(0, $matched->fresh()->amazon_own_stock);
        $this->assertSame(5, $matched->fresh()->amazon_fba_stock);
        $this->assertNull($missing->fresh()->amazon_own_stock);
        $this->assertNull($missing->fresh()->amazon_fba_stock);
        $this->assertSame(Survey::AMAZON_SOURCE_SP_API, $pending->fresh()->amazon_source);
        $this->assertNull($pending->fresh()->amazon_inventory_import_id);
        $this->assertNull($pendingSku->fresh()->amazon_own_stock);
        $this->assertNull($pendingSku->fresh()->amazon_fba_stock);
        $this->assertSame(5, $alreadySku->fresh()->amazon_fba_stock);
        $this->assertNull($legacy->fresh()->amazon_source);
        $this->assertSame(7, $legacySku->fresh()->amazon_own_stock);
        $this->assertSame(8, $legacySku->fresh()->amazon_fba_stock);
        $this->assertSame('保存メモ', $legacySku->fresh()->memo);
        $this->assertDatabaseHas('survey_files', ['id' => $legacyFile->id, 'survey_id' => $legacy->id]);
        $this->assertNull($ambiguous->fresh()->amazon_source);
        $this->assertSame(0, $ambiguousSku->fresh()->amazon_own_stock);

        $rerun = $this->runBackfill();
        $this->assertSame(0, $rerun['summary']['surveysToChange']);
        $this->assertSame(0, $rerun['summary']['skusToChange']);
        $this->assertSame($legacyFingerprint, collect($rerun['surveys'])->keyBy('surveyId')[$legacy->id]['protectedFingerprint']);
    }

    public function test_invalid_successful_import_stops_without_partially_classifying_a_survey(): void
    {
        $this->import('2026-10-15', '2026-10-15 10:00:00');
        $survey = $this->survey('2026-10-15 08:00:00', false, null);
        $sku = $this->sku($survey, 'SKU-M', 0, 0);

        $this->assertSame(1, Artisan::call('survey:amazon-backfill', ['--apply' => true]));
        $this->assertNull($survey->fresh()->amazon_source);
        $this->assertSame(0, $sku->fresh()->amazon_own_stock);
    }

    public function test_sync_failure_rolls_back_the_source_change_for_that_survey(): void
    {
        $survey = $this->survey('2026-10-15 08:00:00', false, null);
        $sku = $this->sku($survey, 'SKU-M', 0, 0);
        app()->instance(SurveyAmazonInventorySyncService::class, new class(new BulkRowWriter) extends SurveyAmazonInventorySyncService
        {
            public function syncSurvey(int $surveyId): ?int
            {
                throw new \RuntimeException('同期失敗');
            }
        });

        $this->assertSame(1, Artisan::call('survey:amazon-backfill', ['--apply' => true]));
        $this->assertNull($survey->fresh()->amazon_source);
        $this->assertSame(0, $sku->fresh()->amazon_own_stock);
    }

    /** @param  array<string, bool>  $options */
    private function runBackfill(array $options = []): array
    {
        $this->assertSame(0, Artisan::call('survey:amazon-backfill', ['--json' => true] + $options));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function survey(string $executedAt, bool $includesAmazon, ?int $importId, ?string $source = null): Survey
    {
        return Survey::factory()->create([
            'executed_at' => $executedAt,
            'includes_amazon_inventory' => $includesAmazon,
            'amazon_inventory_import_id' => $importId,
            'amazon_source' => $source,
        ]);
    }

    private function sku(Survey $survey, string $code, ?int $own, ?int $fba, ?string $memo = null): SurveySku
    {
        $product = $survey->products()->first() ?? SurveyProduct::factory()->for($survey)->create();

        return SurveySku::factory()->for($product, 'product')->create([
            'sku_code' => $code,
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
            'memo' => $memo,
        ]);
    }

    private function file(Survey $survey, string $type): SurveyFile
    {
        return SurveyFile::factory()->for($survey)->create(['file_type' => $type]);
    }

    private function import(string $day, string $basisAt, string $status = 'succeeded'): AmazonInventoryImport
    {
        return AmazonInventoryImport::query()->create([
            'status' => $status,
            'trigger_source' => 'manual',
            'snapshot_date' => $day,
            'basis_at' => $basisAt,
            'completed_at' => $basisAt,
        ]);
    }

    private function snapshot(AmazonInventoryImport $import, string $code, int $own, int $fba): void
    {
        $import->snapshots()->create([
            'product_code' => 'PRODUCT-1',
            'sku_code' => $code,
            'asin' => 'B000000001',
            'amazon_own_stock' => $own,
            'amazon_fba_stock' => $fba,
            'basis_at' => $import->basis_at,
        ]);
    }
}
