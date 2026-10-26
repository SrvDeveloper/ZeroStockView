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
     * 既定値を明示して定義し直し、自動の更新を外す。値は変わらない。本番は SQL で外し済みで、同じ定義になる。
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
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($column): void {
                $table->timestamp($column)->useCurrent()->change();
            });
        }
    }

    /** 自動の更新は付け直さない（付け直すと、行の更新で日時が書き換わる状態に戻る） */
    public function down(): void {}
};
