<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_check_results', function (Blueprint $table) {
            $table->timestamp('telemetry_sent_at')->nullable()->after('ai_diagnosis');
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('telemetry_token', 64)->nullable()->after('share_telemetry');
            $table->string('telemetry_endpoint')->default('https://improve.ypsilon.dev/api/ingest')->after('telemetry_token');
        });
    }

    public function down(): void
    {
        Schema::table('health_check_results', function (Blueprint $table) {
            $table->dropColumn('telemetry_sent_at');
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['telemetry_token', 'telemetry_endpoint']);
        });
    }
};
