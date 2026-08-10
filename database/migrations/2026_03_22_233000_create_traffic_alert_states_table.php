<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alert state tracking for suppression/cool-down.
 *
 * Dedup key: domain_id + alert_type.
 * States: open → suppressed → resolved.
 *
 * Evidence (hit count, latest value, latest summary) keeps updating
 * even when the alert is suppressed, so the "open" state always
 * reflects the freshest data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('alert_type', 64)->comment('e.g. high_latency, high_5xx_rate, injection_failure');

            // State machine: open, suppressed, resolved
            $table->string('state', 16)->default('open');

            // Severity of the most recent trigger
            $table->string('severity', 16)->default('warning');

            // Evidence — updated even while suppressed
            $table->unsignedInteger('hit_count')->default(1)->comment('Times triggered since first open');
            $table->float('latest_value')->nullable()->comment('e.g. p95=4200, error_rate=0.12');
            $table->text('latest_message')->nullable();
            $table->json('evidence_payload')->nullable()->comment('Full summary snapshot');

            // Timing
            $table->timestamp('first_fired_at');
            $table->timestamp('last_fired_at');
            $table->timestamp('suppressed_until')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // Dedup key: one active alert per domain per type
            $table->unique(['domain_id', 'alert_type', 'state'], 'traffic_alerts_dedup');
            $table->index(['state', 'suppressed_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_alert_states');
    }
};
