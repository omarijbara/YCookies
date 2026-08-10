<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('traffic_rum_events', 'banner_expected_count')) {
            Schema::table('traffic_rum_events', function (Blueprint $table) {
                $table->unsignedInteger('banner_expected_count')->default(0)->after('ttfb_histogram');
            });
        }

        // Ensure ycookies_ro can see the new column
        try {
            DB::statement("GRANT SELECT ON `ycookies`.`traffic_rum_events` TO 'ycookies_ro'@'%'");
        } catch (\Throwable $e) {
            // User may not exist in dev
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('traffic_rum_events', 'banner_expected_count')) {
            Schema::table('traffic_rum_events', function (Blueprint $table) {
                $table->dropColumn('banner_expected_count');
            });
        }
    }
};
