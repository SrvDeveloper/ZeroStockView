<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_connection_settings', function (Blueprint $table) {
            $table->id();
            $table->string('api_key_hash', 64);
            $table->string('api_key_suffix', 8);
            $table->json('allowed_networks');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_connection_settings');
    }
};
