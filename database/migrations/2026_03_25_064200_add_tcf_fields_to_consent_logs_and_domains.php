<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_logs', function (Blueprint $table) {
            $table->longText('tc_string')->nullable()->after('services_granted');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->string('cmp_id', 10)->nullable()->after('tcf_config');
        });
    }

    public function down(): void
    {
        Schema::table('consent_logs', function (Blueprint $table) {
            $table->dropColumn('tc_string');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('cmp_id');
        });
    }
};
