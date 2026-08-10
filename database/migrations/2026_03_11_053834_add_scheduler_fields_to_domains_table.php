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
        Schema::table('domains', function (Blueprint $table) {
            $table->string('scheduler_mode')->default('traffic');
            $table->boolean('scheduler_enabled')->default(true);
            $table->integer('lock_minutes')->default(5);
            $table->integer('max_scans_per_day')->default(10);
            $table->string('webcron_token')->nullable();
            $table->timestamp('last_scan_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'scheduler_mode',
                'scheduler_enabled',
                'lock_minutes',
                'max_scans_per_day',
                'webcron_token',
                'last_scan_at',
            ]);
        });
    }
};
