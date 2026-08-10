<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('health_check_enabled')->default(false);
            $table->string('health_status')->default('never_checked');
            $table->timestamp('last_health_check_at')->nullable();
            $table->unsignedInteger('health_check_interval_minutes')->default(60);
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'health_check_enabled',
                'health_status',
                'last_health_check_at',
                'health_check_interval_minutes',
            ]);
        });
    }
};
