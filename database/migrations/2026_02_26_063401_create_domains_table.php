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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., 'client-blog.com'
            $table->uuid('site_id')->unique(); // Unique identifier for the JS snippet
            $table->boolean('is_active')->default(true);
            $table->json('theme_settings')->nullable(); // Colors, fonts, banner position
            $table->json('translations')->nullable(); // Multi-language text for the banner
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
