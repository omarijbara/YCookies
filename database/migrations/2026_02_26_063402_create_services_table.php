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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cookie_group_id')->constrained()->cascadeOnDelete();
            $table->string('key')->index(); // e.g., 'google-analytics'
            $table->string('name'); // e.g., 'Google Analytics'
            $table->string('provider'); // The company behind the service
            $table->text('purpose')->nullable(); // Explanatory text for legal reasons
            $table->json('cookie_names')->nullable(); // Array of cookies this service drops (e.g., ['_ga', '_gid'])
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['cookie_group_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
