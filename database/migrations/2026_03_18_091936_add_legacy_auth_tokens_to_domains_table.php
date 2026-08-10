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
        Schema::table('domains', function (Blueprint $table) {
            $table->string('origin_auth_token_legacy', 64)->nullable()->after('origin_auth_token')->comment('Keeps the old token temporarily valid during rotation');
            $table->timestamp('origin_auth_legacy_expires_at')->nullable()->after('origin_auth_token_legacy')->comment('When the origin stops accepting the legacy token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['origin_auth_token_legacy', 'origin_auth_legacy_expires_at']);
        });
    }
};
