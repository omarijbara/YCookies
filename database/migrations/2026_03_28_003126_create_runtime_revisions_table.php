<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->unsignedBigInteger('revision_number');
            $table->string('schema_version', 16)->default('1.0.0');
            $table->enum('status', ['draft', 'published', 'failed', 'rolled_back'])->default('draft');

            // Artifact storage — LONGTEXT for transactional atomicity with publish
            $table->longText('manifest_json');
            $table->string('manifest_hash', 64);
            $table->text('manifest_signature')->nullable();

            $table->longText('base_artifact_json');
            $table->string('base_artifact_hash', 64);

            $table->longText('route_index_json')->nullable();
            $table->string('route_index_hash', 64)->nullable();

            // Compilation metadata
            $table->foreignId('compiled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('compile_inputs_hash', 64)
                ->comment('SHA-256 of source data at compile time — skip recompile if unchanged');

            // Lifecycle timestamps
            $table->timestamp('published_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            // Constraints
            $table->unique(['domain_id', 'revision_number']);
            $table->index(['domain_id', 'status']);
        });

        Schema::create('runtime_overlays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('runtime_revisions')->cascadeOnDelete();
            $table->string('overlay_id');
            $table->string('route_pattern');
            $table->longText('overlay_json');
            $table->string('overlay_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['revision_id', 'overlay_id']);
        });

        // Add manifest pointer and feature flag to domains
        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('active_revision_id')
                ->nullable()
                ->constrained('runtime_revisions')
                ->nullOnDelete();
            $table->boolean('manifest_enabled')
                ->default(false)
                ->comment('Feature flag: when true, consumers read from manifest instead of dynamic assembly');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_revision_id');
            $table->dropColumn('manifest_enabled');
        });

        Schema::dropIfExists('runtime_overlays');
        Schema::dropIfExists('runtime_revisions');
    }
};
