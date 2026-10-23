<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** BOSS の在庫変動履歴の取込記録（docs/BOSS在庫の休日補完 BHR-03）。ファイルは期限が来たら消し、記録の行は残す */
    public function up(): void
    {
        Schema::create('boss_inventory_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('original_name');
            $table->string('disk', 50)->nullable();
            $table->string('stored_path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->dateTime('period_from');
            $table->dateTime('period_to');
            $table->unsignedInteger('row_count');
            $table->json('summary')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('uploaded_at');
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boss_inventory_histories');
    }
};
