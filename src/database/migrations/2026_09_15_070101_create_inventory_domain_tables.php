<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code', 100)->unique();
            $table->string('brand', 100);
            $table->string('category', 100);
            $table->string('parent_asin', 20)->nullable()->index();
            $table->string('status', 20)->default('active');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
        });

        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku_code', 150)->unique();
            $table->string('child_asin', 20)->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->string('tq_item_no', 100);
            $table->string('tq_color_no', 50);
            $table->string('tq_size', 50);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['product_id', 'sort_order']);
            $table->unique(['tq_item_no', 'tq_color_no', 'tq_size']);
        });

        Schema::create('import_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->unique();
            $table->timestamps();
        });

        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->timestamp('executed_at')->index();
            $table->timestamps();
        });

        Schema::create('survey_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('product_code', 100);
            $table->string('brand', 100);
            $table->string('category', 100);
            $table->string('parent_asin', 20)->nullable();
            $table->string('status', 20);
            $table->timestamp('source_updated_at')->nullable();
            $table->unsignedTinyInteger('sort_order');
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'product_code']);
            $table->unique(['survey_id', 'sort_order']);
        });

        Schema::create('survey_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_product_id')->constrained()->cascadeOnDelete();
            $table->string('sku_code', 150);
            $table->string('child_asin', 20)->nullable();
            $table->string('status', 20);
            $table->string('tq_item_no', 100);
            $table->string('tq_color_no', 50);
            $table->string('tq_size', 50);
            $table->unsignedSmallInteger('sort_order');
            $table->unsignedInteger('amazon_own_stock')->default(0);
            $table->unsignedInteger('amazon_fba_stock')->default(0);
            $table->unsignedInteger('boss_own_stock')->default(0);
            $table->unsignedInteger('boss_rfc_stock')->default(0);
            $table->unsignedInteger('free_stock')->default(0);
            $table->unsignedInteger('ec_stock')->default(0);
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->unique(['survey_product_id', 'sku_code']);
            $table->unique(['survey_product_id', 'sort_order']);
        });

        Schema::create('survey_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('file_type', 50);
            $table->string('original_name');
            $table->string('disk', 50)->default('local');
            $table->string('stored_path', 1024);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('uploaded_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['survey_id', 'file_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_files');
        Schema::dropIfExists('survey_skus');
        Schema::dropIfExists('survey_products');
        Schema::dropIfExists('surveys');
        Schema::dropIfExists('import_targets');
        Schema::dropIfExists('skus');
        Schema::dropIfExists('products');
    }
};
