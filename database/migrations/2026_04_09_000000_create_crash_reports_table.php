<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crash_reports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 60)->index();           // health-checker, cookie-scanner, etc.
            $table->string('level', 20)->default('error');    // error, warning, critical
            $table->text('message');
            $table->longText('stack_trace')->nullable();
            $table->json('context')->nullable();              // file, line, url, domain, etc.
            $table->string('fingerprint', 64)->index();       // SHA-256 dedup key
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('telemetry_sent_at')->nullable()->index(); // null = not yet pushed
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crash_reports');
    }
};
