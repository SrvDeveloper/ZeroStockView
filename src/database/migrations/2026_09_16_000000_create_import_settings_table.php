<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        $legacyTargets = DB::table('import_targets')
            ->orderBy('sort_order')
            ->get(['product_id', 'sort_order', 'created_at', 'updated_at']);

        Schema::rename('import_targets', 'legacy_import_targets');

        Schema::create('import_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_setting_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();

            $table->foreign('product_id', 'named_import_targets_product_id_foreign')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['import_setting_id', 'product_id']);
            $table->unique(['import_setting_id', 'sort_order']);
        });

        if ($legacyTargets->isNotEmpty()) {
            $settingId = DB::table('import_settings')->insertGetId([
                'name' => '既定の設定',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('import_targets')->insert($legacyTargets->map(fn (object $target): array => [
                'import_setting_id' => $settingId,
                'product_id' => $target->product_id,
                'sort_order' => $target->sort_order,
                'created_at' => $target->created_at,
                'updated_at' => $target->updated_at,
            ])->all());
        }

        Schema::drop('legacy_import_targets');
    }

    public function down(): void
    {
        $targets = DB::table('import_targets')
            ->where('import_setting_id', DB::table('import_settings')->min('id'))
            ->orderBy('sort_order')
            ->get(['product_id', 'sort_order', 'created_at', 'updated_at']);

        Schema::drop('import_targets');

        Schema::create('import_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->unique();
            $table->timestamps();
        });

        if ($targets->isNotEmpty()) {
            DB::table('import_targets')->insert($targets->map(fn (object $target): array => [
                'product_id' => $target->product_id,
                'sort_order' => $target->sort_order,
                'created_at' => $target->created_at,
                'updated_at' => $target->updated_at,
            ])->all());
        }

        Schema::dropIfExists('import_settings');
    }
};
