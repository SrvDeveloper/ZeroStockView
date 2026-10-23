<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 調査の BOSS 在庫の取得方式（report: BOSS のレポートを取り込んだ／history: 在庫変動履歴から再現した／NULL: BOSS の値がない）。
     * 既存の調査は、BOSS のレポートのファイルが残っているか、BOSS の在庫が1つでも保存されていれば report にする
     * （期限の来た取込ファイルは行ごと消しているため、ファイルだけでは古い調査を見分けられない）。
     */
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table): void {
            $table->string('boss_source', 20)->nullable();
            $table->dateTime('boss_basis_at')->nullable();
            $table->foreignId('boss_inventory_history_id')->nullable()->constrained('boss_inventory_histories')->nullOnDelete();
        });

        DB::table('surveys')
            ->where(fn ($query) => $query
                ->whereExists(fn ($files) => $files->selectRaw('1')->from('survey_files')
                    ->whereColumn('survey_files.survey_id', 'surveys.id')
                    ->where('survey_files.file_type', '倉庫毎の在庫数レポート'))
                ->orWhereExists(fn ($skus) => $skus->selectRaw('1')->from('survey_skus')
                    ->join('survey_products', 'survey_products.id', '=', 'survey_skus.survey_product_id')
                    ->whereColumn('survey_products.survey_id', 'surveys.id')
                    ->where(fn ($stock) => $stock->whereNotNull('survey_skus.boss_own_stock')->orWhereNotNull('survey_skus.boss_rfc_stock'))))
            ->update(['boss_source' => 'report']);
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('boss_inventory_history_id');
            $table->dropColumn(['boss_source', 'boss_basis_at']);
        });
    }
};
