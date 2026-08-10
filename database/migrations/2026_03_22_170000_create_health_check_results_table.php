<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('domain_name');
            $table->string('source');                     // manual | scheduled
            $table->string('status');                     // healthy | warning | failing
            $table->unsignedInteger('checks_total');
            $table->unsignedInteger('checks_passed');
            $table->unsignedInteger('checks_warned');
            $table->unsignedInteger('checks_failed');
            $table->json('checks');                       // individual check results
            $table->json('response_times');               // per-check timing
            $table->json('headers');                      // important response headers
            $table->json('evidence')->nullable();         // logs, errors, debug info
            $table->unsignedInteger('duration_ms');
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['domain_id', 'checked_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_check_results');
    }
};
