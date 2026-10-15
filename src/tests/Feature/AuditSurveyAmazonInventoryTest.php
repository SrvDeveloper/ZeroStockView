<?php

namespace Tests\Feature;

use App\Models\AmazonInventoryImport;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditSurveyAmazonInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_classifies_history_and_reports_day_mismatches_without_writing(): void
    {
        $day = '2026-10-15';
        $previous = $this->import('2026-10-14', '2026-10-14 10:00:00');
        $first = $this->import($day, '2026-10-15 09:00:00');
        $latest = $this->import($day, '2026-10-15 10:00:00');
        $this->import($day, '2026-10-15 11:00:00', 'failed');

        $legacy = $this->survey(true, null, 7);
        SurveyFile::factory()->for($legacy)->create(['file_type' => '在庫商品レポート']);
        SurveyFile::factory()->for($legacy)->create(['file_type' => 'FBA在庫管理レポート']);

        $threeFile = $this->survey(false, $previous->id, 0);
        $threeFile->update(['executed_at' => '2026-10-15 00:30:00']);
        foreach (['倉庫毎の在庫数レポート', 'KEEP一覧表', '在庫一覧照会表'] as $type) {
            SurveyFile::factory()->for($threeFile)->create(['file_type' => $type]);
        }

        $uncertain = $this->survey(true, null, 0);
        $contradiction = $this->survey(false, $first->id, 2);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame(0, Artisan::call('survey:amazon-audit', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $rows = collect($report['surveys'])->keyBy('surveyId');

        $this->assertSame(['legacy_file' => 1, 'three_file' => 1, 'ambiguous' => 2], $report['summary']);
        $this->assertSame(2, $report['successfulImportsByDay'][$day] ?? null);
        $this->assertSame('legacy_file', $rows[$legacy->id]['classification']);
        $this->assertSame(7, $rows[$legacy->id]['storedAmazonOwnTotal']);
        $this->assertSame('three_file', $rows[$threeFile->id]['classification']);
        $this->assertSame($day, $rows[$threeFile->id]['surveyDate']);
        $this->assertSame($latest->id, $rows[$threeFile->id]['latestSuccessfulImportId']);
        $this->assertContains('適用済み取込が同日の成功分ではない', $rows[$threeFile->id]['reasons']);
        $this->assertContains('同日の最新成功分が未適用', $rows[$threeFile->id]['reasons']);
        $this->assertSame('ambiguous', $rows[$uncertain->id]['classification']);
        $this->assertSame('ambiguous', $rows[$contradiction->id]['classification']);
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $sql) === 1)));
        $this->assertDatabaseHas('survey_skus', ['survey_product_id' => $threeFile->products()->first()->id, 'amazon_own_stock' => 0]);
    }

    private function survey(bool $includesAmazon, ?int $importId, int $ownStock): Survey
    {
        $survey = Survey::factory()->create([
            'executed_at' => '2026-10-15 09:30:00',
            'includes_amazon_inventory' => $includesAmazon,
            'amazon_inventory_import_id' => $importId,
        ]);
        $product = SurveyProduct::factory()->for($survey)->create();
        SurveySku::factory()->for($product, 'product')->create([
            'amazon_own_stock' => $ownStock,
            'amazon_fba_stock' => 0,
        ]);

        return $survey;
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
}
