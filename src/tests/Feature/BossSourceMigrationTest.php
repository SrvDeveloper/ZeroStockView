<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyProduct;
use App\Models\SurveySku;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * 既存の調査の BOSS の取得方式を埋めるマイグレーション（docs/BOSS在庫の休日補完 BHR-03）。
 * SQLite は外部キーの列を足すときに表を作り直す。トランザクションの中では外部キーを止められず子の行が消えるので、
 * RefreshDatabase（テストごとのトランザクション）ではなく DatabaseMigrations を使う。
 */
class BossSourceMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_marks_existing_surveys_with_a_boss_report_or_saved_boss_stock_as_report(): void
    {
        $migration = require database_path('migrations/2026_10_24_010000_add_boss_source_to_surveys_table.php');
        $migration->down();
        $withFile = Survey::factory()->create();
        SurveyFile::factory()->for($withFile)->create(['file_type' => '倉庫毎の在庫数レポート']);
        // 取込ファイルは期限が来ると行ごと消えるので、BOSS の在庫が保存されていればレポートを取り込んだ調査とみなす
        $withStock = Survey::factory()->legacyFile()->create();
        SurveySku::factory()->for(SurveyProduct::factory()->for($withStock), 'product')->create(['boss_own_stock' => 3, 'boss_rfc_stock' => 0]);
        $withoutBoss = Survey::factory()->spApi()->create();
        SurveyFile::factory()->for($withoutBoss)->create(['file_type' => 'KEEP一覧表']);
        SurveySku::factory()->for(SurveyProduct::factory()->for($withoutBoss), 'product')->create(['boss_own_stock' => null, 'boss_rfc_stock' => null]);

        $migration->up();

        $this->assertSame(Survey::BOSS_SOURCE_REPORT, $withFile->fresh()->boss_source);
        $this->assertSame(Survey::BOSS_SOURCE_REPORT, $withStock->fresh()->boss_source);
        $this->assertNull($withoutBoss->fresh()->boss_source);
        // 列を足しても、取込ファイルと調査の行は消えない
        $this->assertSame(2, SurveyFile::query()->count());
        $this->assertSame(2, SurveySku::query()->count());

        // 終わりに全マイグレーションを戻すとき、未確定（NULL）の数量があると戻せないマイグレーションがあるので、作った調査を消しておく
        Survey::query()->delete();
    }
}
