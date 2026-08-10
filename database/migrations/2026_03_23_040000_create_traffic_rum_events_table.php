<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('traffic_rum_events')) {
            // Table already exists (partial previous run) — just ensure grants
            try {
                DB::statement("GRANT SELECT ON `ycookies`.`traffic_rum_events` TO 'ycookies_ro'@'%'");
            } catch (\Throwable $e) {
                // Grant may already exist or user may not exist in dev
            }
            return;
        }

        Schema::create('traffic_rum_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->timestamp('bucket')->comment('Minute bucket');
            $table->string('page_path', 512)->default('/');

            // Counters
            $table->unsignedInteger('pageview_count')->default(0);

            // Browser timing histograms (same bin structure as TrafficMetric)
            $table->json('dcl_histogram')->nullable()->comment('DOMContentLoaded timing');
            $table->json('load_histogram')->nullable()->comment('Load event timing');
            $table->json('fp_histogram')->nullable()->comment('First Paint timing');
            $table->json('ttfb_histogram')->nullable()->comment('Browser-side TTFB');

            // Consent banner
            $table->unsignedInteger('banner_rendered_count')->default(0);
            $table->unsignedInteger('banner_render_time_sum')->default(0)->comment('Sum of render times for averaging');

            // Injection confirmation
            $table->unsignedInteger('injection_confirmed_count')->default(0);
            $table->unsignedInteger('injection_missing_count')->default(0);

            // JS errors (consent-scoped only)
            $table->unsignedInteger('js_error_count')->default(0);
            $table->json('js_errors')->nullable()->comment('{message: count} map, capped at 20 keys');

            $table->timestamps();

            // Composite unique for upsert
            $table->unique(['domain_id', 'bucket', 'page_path'], 'rum_bucket_unique');
            $table->index(['bucket'], 'rum_bucket_idx');
            $table->index(['domain_id', 'bucket'], 'rum_domain_bucket_idx');
        });

        // Grant SELECT on the new table to the read-only dashboard user
        try {
            DB::statement("GRANT SELECT ON `ycookies`.`traffic_rum_events` TO 'ycookies_ro'@'%'");
        } catch (\Throwable $e) {
            // User may not exist in dev environments
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_rum_events');
    }
};
