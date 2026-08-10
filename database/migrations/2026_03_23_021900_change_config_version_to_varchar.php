<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change config_version from int unsigned to varchar(64).
 *
 * The Node proxy sends composite version strings like "157:4103381401"
 * which truncate silently on an int column. varchar preserves the full
 * identifier for incident correlation and dashboard display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traffic_metrics', function (Blueprint $table) {
            $table->string('config_version', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('traffic_metrics', function (Blueprint $table) {
            $table->unsignedInteger('config_version')->nullable()->change();
        });
    }
};
