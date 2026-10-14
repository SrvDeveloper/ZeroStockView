<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table): void {
            $table->boolean('includes_amazon_inventory')->default(true);
            $table->foreignId('amazon_inventory_import_id')->nullable()->constrained('amazon_inventory_imports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('amazon_inventory_import_id');
            $table->dropColumn('includes_amazon_inventory');
        });
    }
};
