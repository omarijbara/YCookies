<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Services: add Consent Execution Registry v2 fields ──
        Schema::table('services', function (Blueprint $table) {
            $table->string('integration_type')->default('browser_tag')
                ->after('template_version')
                ->comment('browser_tag|embed_provider|script_blocker|style_blocker|server_destination|functional_widget|tcf_vendor_adapter');

            $table->string('provider_key')->nullable()
                ->after('integration_type')
                ->comment('Unique key for provider-level consent resolution (e.g. "x", "youtube")');

            $table->json('domains')->nullable()
                ->after('provider_key')
                ->comment('Known domains of this service for matching');

            $table->json('consent_mode_mapping')->nullable()
                ->after('domains')
                ->comment('Google Consent Mode v2 signal mapping');

            $table->json('blocking_rules')->nullable()
                ->after('consent_mode_mapping')
                ->comment('Unified blocking: iframe_patterns, script_patterns, selectors');

            $table->boolean('supports_accept_once')->default(false)
                ->after('blocking_rules')
                ->comment('Embed: allow per-instance consent');

            $table->boolean('supports_accept_provider')->default(false)
                ->after('supports_accept_once')
                ->comment('Embed: allow per-provider consent');

            $table->json('ui_config')->nullable()
                ->after('supports_accept_provider')
                ->comment('Placeholder variant, title, description, actions');

            $table->json('compliance')->nullable()
                ->after('ui_config')
                ->comment('Privacy policy URL, writes_cookies, data_transfer flags');

            $table->json('test_manifest')->nullable()
                ->after('compliance')
                ->comment('Expected blocked requests for QA validation');
        });

        // ── Content Blockers: add provider-level consent fields ──
        Schema::table('content_blockers', function (Blueprint $table) {
            $table->string('provider_key')->nullable()
                ->after('key')
                ->comment('Unique key for provider-level consent (e.g. "youtube", "x")');

            $table->boolean('supports_accept_once')->default(true)
                ->after('provider_key')
                ->comment('Show "Load this content" button');

            $table->boolean('supports_accept_provider')->default(true)
                ->after('supports_accept_once')
                ->comment('Show "Always allow [provider]" button');

            $table->json('consent_mode_mapping')->nullable()
                ->after('supports_accept_provider')
                ->comment('Google Consent Mode v2 signal mapping');
        });

        // ── Consent Logs: add provider_overrides for per-provider consent ──
        Schema::table('consent_logs', function (Blueprint $table) {
            $table->json('provider_overrides')->nullable()
                ->after('tc_string')
                ->comment('Array of provider keys the user explicitly allowed');
        });

        // ── Backfill existing services with correct integration_type ──
        // Services in the external_media group are embed_providers
        // Services in the essential group are functional_widgets
        // Everything else remains browser_tag (the default)
        \Illuminate\Support\Facades\DB::table('services')
            ->join('cookie_groups', 'services.cookie_group_id', '=', 'cookie_groups.id')
            ->where('cookie_groups.key', 'external_media')
            ->update(['services.integration_type' => 'embed_provider']);

        \Illuminate\Support\Facades\DB::table('services')
            ->join('cookie_groups', 'services.cookie_group_id', '=', 'cookie_groups.id')
            ->where('cookie_groups.key', 'essential')
            ->update(['services.integration_type' => 'functional_widget']);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'integration_type',
                'provider_key',
                'domains',
                'consent_mode_mapping',
                'blocking_rules',
                'supports_accept_once',
                'supports_accept_provider',
                'ui_config',
                'compliance',
                'test_manifest',
            ]);
        });

        Schema::table('content_blockers', function (Blueprint $table) {
            $table->dropColumn([
                'provider_key',
                'supports_accept_once',
                'supports_accept_provider',
                'consent_mode_mapping',
            ]);
        });

        Schema::table('consent_logs', function (Blueprint $table) {
            $table->dropColumn('provider_overrides');
        });
    }
};
