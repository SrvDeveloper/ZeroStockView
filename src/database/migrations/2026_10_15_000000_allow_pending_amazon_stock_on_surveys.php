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
            // Historical rows remain unresolved until the audited backfill.
            $table->string('amazon_source', 20)->nullable()->after('includes_amazon_inventory');
            $table->index(['amazon_source', 'executed_at']);
        });

        Schema::table('survey_skus', function (Blueprint $table): void {
            $table->unsignedInteger('amazon_own_stock')->nullable()->default(null)->change();
            $table->unsignedInteger('amazon_fba_stock')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('surveys')->whereNotNull('amazon_source')->exists()
            || DB::table('survey_skus')->whereNull('amazon_own_stock')->orWhereNull('amazon_fba_stock')->exists()) {
            throw new RuntimeException('Amazon取得方式または取込待ちの数量が保存されているため、マイグレーションを戻せません。');
        }

        Schema::table('survey_skus', function (Blueprint $table): void {
            $table->unsignedInteger('amazon_own_stock')->default(0)->nullable(false)->change();
            $table->unsignedInteger('amazon_fba_stock')->default(0)->nullable(false)->change();
        });

        Schema::table('surveys', function (Blueprint $table): void {
            $table->dropIndex(['amazon_source', 'executed_at']);
            $table->dropColumn('amazon_source');
        });
    }
};
