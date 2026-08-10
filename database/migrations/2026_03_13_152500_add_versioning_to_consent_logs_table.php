<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('consent_logs', 'cookie_version')) {
                $table->unsignedInteger('cookie_version')->default(1)->after('consent_type');
            }
            if (!Schema::hasColumn('consent_logs', 'is_latest')) {
                $table->boolean('is_latest')->default(true)->after('cookie_version');
            }

            // Add index for fast UID lookups
            if (!Schema::hasIndex('consent_logs', 'consent_logs_consent_uid_is_latest_index')) {
                $table->index(['consent_uid', 'is_latest']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('consent_logs', function (Blueprint $table) {
            $table->dropColumn(['cookie_version', 'is_latest']);
        });
    }
};
