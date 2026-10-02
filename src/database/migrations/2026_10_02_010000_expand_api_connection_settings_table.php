<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_connection_settings', function (Blueprint $table) {
            $table->boolean('enabled')->default(false)->after('id');
            $table->timestamp('api_key_issued_at')->nullable()->after('api_key_suffix');
            $table->string('api_key_hash', 64)->nullable()->change();
            $table->string('api_key_suffix', 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('api_connection_settings')->whereNull('api_key_hash')->delete();

        Schema::table('api_connection_settings', function (Blueprint $table) {
            $table->string('api_key_hash', 64)->nullable(false)->change();
            $table->string('api_key_suffix', 8)->nullable(false)->change();
            $table->dropColumn(['enabled', 'api_key_issued_at']);
        });
    }
};
