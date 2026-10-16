<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table): void {
            // Historical duplicate days remain intact; new imports claim one row per day.
            $table->date('survey_date')->nullable()->unique();
        });

        Schema::table('survey_skus', function (Blueprint $table): void {
            foreach (['boss_own_stock', 'boss_rfc_stock', 'free_stock', 'ec_stock'] as $column) {
                $table->unsignedInteger($column)->nullable()->default(null)->change();
            }
        });
    }

    public function down(): void
    {
        if (DB::table('survey_skus')->where(function ($query): void {
            foreach (['boss_own_stock', 'boss_rfc_stock', 'free_stock', 'ec_stock'] as $column) {
                $query->orWhereNull($column);
            }
        })->exists()) {
            throw new RuntimeException('取込待ちの数量が保存されているため、マイグレーションを戻せません。');
        }

        Schema::table('survey_skus', function (Blueprint $table): void {
            foreach (['boss_own_stock', 'boss_rfc_stock', 'free_stock', 'ec_stock'] as $column) {
                $table->unsignedInteger($column)->default(0)->nullable(false)->change();
            }
        });

        Schema::table('surveys', function (Blueprint $table): void {
            $table->dropUnique(['survey_date']);
            $table->dropColumn('survey_date');
        });
    }
};
