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
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->string('primary_proxy_uuid')->nullable()->after('app_uuids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->dropColumn('primary_proxy_uuid');
        });
    }
};
