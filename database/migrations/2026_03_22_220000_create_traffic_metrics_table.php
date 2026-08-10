<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregates-first traffic metrics table.
 *
 * Stores minute-bucketed aggregates per domain, NOT raw per-request rows.
 *
 * Design decisions:
 * - Latency stored as histogram bins (JSON), NOT pre-computed percentiles.
 *   This is mergeable across multiple batches/proxy instances.
 *   Percentiles (p50/p95/p99) are derived at read time from merged bins.
 * - Status codes split into 2xx/3xx/4xx/5xx for accurate alerting.
 * - Batch-level idempotency via processed_metric_batches table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('bucket')->comment('Minute-rounded timestamp');

            // Request volume — split by status class
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('status_2xx')->default(0);
            $table->unsignedInteger('status_3xx')->default(0);
            $table->unsignedInteger('status_4xx')->default(0);
            $table->unsignedInteger('status_5xx')->default(0);

            // Latency histogram bins (mergeable across batches)
            // JSON: {"0_50":12,"50_100":8,"100_200":5,"200_500":3,"500_1000":1,"1000_2000":0,"2000_5000":0,"5000_inf":0}
            $table->json('latency_histogram')->nullable()->comment('Latency distribution bins in ms');
            $table->json('ttfb_histogram')->nullable()->comment('Origin TTFB distribution bins in ms');

            // Cache performance
            $table->unsignedInteger('cache_hits')->default(0);
            $table->unsignedInteger('cache_misses')->default(0);

            // Injection tracking (detailed)
            $table->unsignedInteger('html_responses')->default(0)->comment('Total HTML responses seen');
            $table->unsignedInteger('inject_attempted')->default(0);
            $table->unsignedInteger('inject_succeeded')->default(0);
            $table->unsignedInteger('inject_failed')->default(0);
            $table->unsignedInteger('passthrough_count')->default(0)->comment('Non-HTML passthrough');

            // Blocking counts
            $table->unsignedInteger('blocked_scripts_total')->default(0);
            $table->unsignedInteger('blocked_content_total')->default(0);
            $table->unsignedInteger('filtered_cookies_total')->default(0);

            // Transfer volume
            $table->unsignedBigInteger('bytes_in_total')->default(0);
            $table->unsignedBigInteger('bytes_out_total')->default(0);

            // Error breakdown + version tracking
            $table->json('error_codes')->nullable()->comment('{"TIMEOUT":3,"UPSTREAM_ERROR":1}');
            $table->string('proxy_version', 16)->nullable();
            $table->unsignedInteger('config_version')->nullable();

            $table->timestamps();

            // Composite unique: one row per domain per minute
            $table->unique(['domain_id', 'bucket'], 'traffic_metrics_domain_bucket_unique');
            $table->index('bucket');
        });

        // Batch-level idempotency table
        // Short-lived: entries cleaned after 1 hour TTL
        Schema::create('processed_metric_batches', function (Blueprint $table) {
            $table->string('batch_id', 36)->primary();
            $table->timestamp('processed_at')->useCurrent();
            $table->index('processed_at'); // for TTL cleanup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_metric_batches');
        Schema::dropIfExists('traffic_metrics');
    }
};
