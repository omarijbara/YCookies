<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('discovered_resources')) {
            return;
        }

        Schema::create('discovered_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('provider_host');
            $table->string('resource_type'); // script, style, service
            $table->text('sample_url');
            $table->string('status')->default('pending'); // pending, resolved, ignored
            $table->unsignedInteger('hit_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_to_type')->nullable(); // script_blocker, content_blocker, service
            $table->unsignedBigInteger('resolved_to_id')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'provider_host', 'resource_type']);
            $table->index(['domain_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovered_resources');
    }
};
