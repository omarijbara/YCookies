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
        Schema::create('consent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('consent_uid')->index(); // The unique ID given to the visitor's browser
            $table->string('ip_hash')->nullable(); // Anonymized IP hash for geographical proof (must not store raw IP)
            $table->text('user_agent')->nullable();
            $table->json('consents_granted'); // E.g., ['essential' => true, 'marketing' => false]
            $table->json('services_granted'); // E.g., ['google-analytics', 'facebook-pixel']
            $table->string('consent_type'); // 'implicit', 'explicit', 'renewed'
            $table->timestamps();

            $table->index(['domain_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consent_logs');
    }
};
