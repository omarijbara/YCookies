<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->json('health_check_overrides')->nullable();
            $table->timestamp('last_health_success_at')->nullable();
            $table->unsignedInteger('health_consecutive_failures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'health_check_overrides',
                'last_health_success_at',
                'health_consecutive_failures',
            ]);
        });
    }
};
