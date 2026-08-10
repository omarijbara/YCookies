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
        Schema::create('cookie_bars', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('theme_settings')->nullable();
            $table->json('translations')->nullable();
            $table->timestamps();
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('cookie_bar_id')->nullable()->constrained('cookie_bars')->nullOnDelete();
            // We remove these from the domains table since they are moved to cookie_bars
            $table->dropColumn(['theme_settings', 'translations']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropForeign(['cookie_bar_id']);
            $table->dropColumn('cookie_bar_id');
            $table->json('theme_settings')->nullable();
            $table->json('translations')->nullable();
        });

        Schema::dropIfExists('cookie_bars');
    }
};
