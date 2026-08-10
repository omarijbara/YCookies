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
        Schema::table('service_settings', function (Blueprint $table) {
            $table->string('gtm_id')->nullable()->after('service_id');
            $table->string('ga_id')->nullable()->after('gtm_id');
            $table->string('pixel_id')->nullable()->after('ga_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_settings', function (Blueprint $table) {
            $table->dropColumn(['gtm_id', 'ga_id', 'pixel_id']);
        });
    }
};
