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
        Schema::create('script_blockers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key')->index(); // unique identifier like 'google-analytics'
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('handles')->nullable();
            $table->json('phrases')->nullable();
            $table->text('on_exist')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('script_blockers');
    }
};
