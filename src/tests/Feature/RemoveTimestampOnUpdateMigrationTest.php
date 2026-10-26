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

    public function test_migration_redefines_the_timestamp_columns_with_an_explicit_default_on_mysql_and_mariadb(): void
    {
        $default = config('database.default');
        try {
            foreach (['mysql', 'mariadb'] as $connection) {
                // マイグレーションは既定の接続の種類を見るので、既定を切り替える
                config(['database.default' => $connection]);
                $migration = require database_path(self::MIGRATION);

                $queries = array_column(DB::connection($connection)->pretend(fn () => $migration->up()), 'query');

                $this->assertSame([
                    'alter table `surveys` modify `executed_at` timestamp not null default CURRENT_TIMESTAMP',
                    'alter table `survey_files` modify `uploaded_at` timestamp not null default CURRENT_TIMESTAMP',
                    'alter table `amazon_inventory_snapshots` modify `basis_at` timestamp not null default CURRENT_TIMESTAMP',
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
