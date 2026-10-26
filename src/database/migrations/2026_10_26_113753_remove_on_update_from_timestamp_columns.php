<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL・MariaDB は explicit_defaults_for_timestamp が無効だと、表で最初の TIMESTAMP NOT NULL の列に
     * ON UPDATE CURRENT_TIMESTAMP を自動で付ける。行を更新するたびに、調査日時などが現在時刻に書き換わる
     * （本番では BHR-03 のマイグレーションで全調査の executed_at が書き換わった）。
     * 既定値を明示して定義し直し、自動の更新を外す。値は変わらない。
     * 自動の更新が付いている列だけを直す。本番は SQL で外し済みなので、何も変えない。表を変えると、MariaDB は
     * ほかの列の既定値も確かめ直し、survey_files.expires_at の自動の既定値（0000-00-00）を strict モードで拒むため。
     *
     * @var array<string, string> 表 => 列
     */
    private const COLUMNS = [
        'surveys' => 'executed_at',
        'survey_files' => 'uploaded_at',
        'amazon_inventory_snapshots' => 'basis_at',
    ];

    public function up(): void
    {
        $connection = Schema::getConnection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $updatesOnChange = collect($connection->select(
            "select table_name as table_name, column_name as column_name from information_schema.columns where table_schema = database() and lower(extra) like '%on update%'"
        ))->map(fn (object $row): string => "{$row->table_name}.{$row->column_name}")->all();

        foreach (self::COLUMNS as $tableName => $column) {
            if (! in_array("{$tableName}.{$column}", $updatesOnChange, true)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->timestamp($column)->useCurrent()->change();
            });
        }
    }

    /** 自動の更新は付け直さない（付け直すと、行の更新で日時が書き換わる状態に戻る） */
    public function down(): void {}
};
