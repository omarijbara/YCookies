<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glitch_tip_settings', function (Blueprint $table) {
            $table->id();
            $table->string('url')->default('http://glitchtip-web:8000');
            $table->string('public_url')->default('https://sentry.ypsilon.dev');
            $table->text('api_token')->nullable();
            $table->string('org_slug')->default('default');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glitch_tip_settings');
    }
};
