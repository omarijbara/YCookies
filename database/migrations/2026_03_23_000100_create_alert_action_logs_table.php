<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for operator actions on traffic alerts.
 * Tracks acknowledge, snooze, resolve, reopen, and notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traffic_alert_state_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32)->comment('acknowledge, snooze, resolve, reopen, note');
            $table->text('note')->nullable();
            $table->json('metadata')->nullable()->comment('Extra context: snooze duration, previous state, etc.');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_action_logs');
    }
};
