<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_check_results', function (Blueprint $table) {
            $table->json('ai_diagnosis')->nullable()->after('evidence');
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->boolean('share_telemetry')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('health_check_results', function (Blueprint $table) {
            $table->dropColumn('ai_diagnosis');
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('share_telemetry');
        });
    }
};
