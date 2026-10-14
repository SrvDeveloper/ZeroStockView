<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_inventory_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20);
            $table->string('trigger_source', 20);
            $table->string('source', 20)->default('sp_api');
            $table->unsignedTinyInteger('active_key')->nullable()->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('own_retrieved_at')->nullable();
            $table->timestamp('fba_retrieved_at')->nullable();
            $table->timestamp('basis_at')->nullable()->index();
            $table->date('snapshot_date')->nullable()->index();
            $table->unsignedInteger('target_sku_count')->default(0);
            $table->unsignedInteger('own_row_count')->default(0);
            $table->unsignedInteger('fba_row_count')->default(0);
            $table->unsignedInteger('matched_own_count')->default(0);
            $table->unsignedInteger('matched_fba_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('amazon_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('amazon_inventory_import_id')->constrained('amazon_inventory_imports')->cascadeOnDelete();
            $table->string('product_code', 100)->index();
            $table->string('sku_code', 150)->index();
            $table->string('asin', 20);
            $table->unsignedInteger('amazon_own_stock');
            $table->unsignedInteger('amazon_fba_stock');
            $table->string('source', 20)->default('sp_api');
            $table->timestamp('basis_at');
            $table->timestamps();

            $table->unique(['amazon_inventory_import_id', 'sku_code'], 'amazon_snapshots_import_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_inventory_snapshots');
        Schema::dropIfExists('amazon_inventory_imports');
    }
};
