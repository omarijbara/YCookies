<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Drop existing tables
        Schema::dropIfExists('services');
        Schema::dropIfExists('cookie_groups');
        Schema::dropIfExists('content_blockers');

        // 2. Recreate without domain_id
        Schema::create('cookie_groups', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index(); // e.g., 'essential', 'marketing'
            $table->string('name'); // e.g., 'Essential', 'Marketing'
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false); // Essential cookies cannot be unchecked
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cookie_group_id')->constrained()->cascadeOnDelete();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('provider')->nullable();
            $table->text('purpose')->nullable();
            $table->json('cookie_names')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('content_blockers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('preview_html')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One way migration for now, down is destructive
    }
};
