<?php

namespace Tests\Feature;

use App\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TIMESTAMP の列から ON UPDATE CURRENT_TIMESTAMP を外すマイグレーション。
 * テストに MySQL・MariaDB はないので、それらでは実行せずに出す SQL を確かめる。
 */
class RemoveTimestampOnUpdateMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_26_113753_remove_on_update_from_timestamp_columns.php';

    public function test_migration_leaves_columns_without_on_update_untouched_on_mysql_and_mariadb(): void
    {
        $default = config('database.default');
        try {
            foreach (['mysql', 'mariadb'] as $connection) {
                // マイグレーションは既定の接続の種類を見るので、既定を切り替える
                config(['database.default' => $connection]);
                $migration = require database_path(self::MIGRATION);

                // pretend では information_schema の問い合わせが行を返さないので、自動の更新がない（本番の今の状態）とみなされる
                $queries = array_column(DB::connection($connection)->pretend(fn () => $migration->up()), 'query');

                $this->assertSame([
                    "select table_name as table_name, column_name as column_name from information_schema.columns where table_schema = database() and lower(extra) like '%on update%'",
                ], $queries, $connection);
            }
        } finally {
            // テストの終わりのロールバックは既定の接続で行うので、SQLite に戻す
            config(['database.default' => $default]);
        }
    }

    public function test_migration_does_nothing_on_sqlite(): void
    {
        $survey = Survey::factory()->create(['executed_at' => '2026-10-23 09:01:16']);
        $migration = require database_path(self::MIGRATION);

        $queries = DB::pretend(fn () => $migration->up());

        $this->assertSame([], $queries);
        $this->assertSame('2026-10-23 09:01:16', $survey->fresh()->executed_at->format('Y-m-d H:i:s'));
    }
}
