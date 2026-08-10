<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coolify_settings', function (Blueprint $table) {
            $table->id();
            $table->string('instance_url')->default('https://coolify.revyome.com');
            $table->text('api_token')->nullable(); // encrypted
            $table->json('app_uuids')->nullable(); // JSON array of {uuid, label} pairs
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coolify_settings');
    }
};
