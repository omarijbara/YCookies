<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_traffic_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('report_date');

            // Denormalized KPIs for fast filtering/sorting
            $table->integer('total_requests')->default(0);
            $table->integer('edge_p95_latency_ms')->nullable();
            $table->decimal('inject_rate', 5, 2)->nullable();        // percentage
            $table->decimal('banner_render_rate', 5, 2)->nullable();  // percentage
            $table->integer('alert_count')->default(0);

            // Future-proof blobs
            $table->json('kpi_blob');                         // full aggregated metrics
            $table->string('summary_status', 32);             // stable|degraded|critical|no_data
            $table->json('trend_json')->nullable();            // {vs_prev_day, vs_7d_avg}
            $table->json('recommendations_json')->nullable();  // rule-based plain-language items

            // AI brief — additive, never required for core functionality
            $table->text('ai_brief')->nullable();

            // Notification idempotency — only send once per report
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            // One report per domain per day per group (domain_id=null = group summary)
            $table->unique(['group_id', 'domain_id', 'report_date'], 'dtr_group_domain_date_unique');
            // Fast lookup for group summary rows
            $table->index(['group_id', 'report_date'], 'dtr_group_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_traffic_reports');
    }
};
