<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_products', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->change();
            $table->json('group_memberships')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        if (DB::table('survey_products')->where('sort_order', '>', 255)->exists()) {
            throw new RuntimeException('255件を超える品番が保存されているため、マイグレーションを戻せません。');
        }

        Schema::table('survey_products', function (Blueprint $table): void {
            $table->dropColumn('group_memberships');
            $table->unsignedTinyInteger('sort_order')->change();
        });
    }
};
