<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes for frequently-queried columns.
     *
     * These indexes target the hottest query paths:
     * - API lookups by site_id
     * - Consent log filtering by UID and domain
     * - Language lookups by code
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Used on every API request (config, script delivery, consent logging)
            $table->index(['site_id', 'is_active'], 'idx_domains_site_id_active');
        });

        Schema::table('consent_logs', function (Blueprint $table) {
            // Used in the creating hook to mark previous entries
            $table->index(['consent_uid', 'domain_id', 'is_latest'], 'idx_consent_logs_uid_domain_latest');
            // Used in statistics queries
            $table->index(['domain_id', 'created_at'], 'idx_consent_logs_domain_created');
        });

        Schema::table('languages', function (Blueprint $table) {
            // Used on every config request to validate lang and fetch RTL
            $table->index(['code', 'is_active'], 'idx_languages_code_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex('idx_domains_site_id_active');
        });

        Schema::table('consent_logs', function (Blueprint $table) {
            $table->dropIndex('idx_consent_logs_uid_domain_latest');
            $table->dropIndex('idx_consent_logs_domain_created');
        });

        Schema::table('languages', function (Blueprint $table) {
            $table->dropIndex('idx_languages_code_active');
        });
    }
};
