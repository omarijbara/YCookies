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
        Schema::create('content_blockers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete(); // E.g., This blocker belongs to YouTube
            $table->string('key')->index(); // e.g., 'youtube'
            $table->string('name'); // e.g., 'YouTube Video'
            $table->json('hosts')->nullable(); // Array of URLs to match against: ['youtube.com', 'youtu.be']
            $table->string('preview_image_url')->nullable(); // What to show before the user unblocks the iframe
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['domain_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_blockers');
    }
};
