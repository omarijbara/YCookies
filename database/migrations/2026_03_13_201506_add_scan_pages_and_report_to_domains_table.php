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
            $table->json('scan_pages')->nullable()->after('scan_frequency');
            $table->string('report_email')->nullable()->after('scan_pages');
            $table->boolean('report_enabled')->default(false)->after('report_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['scan_pages', 'report_email', 'report_enabled']);
        });
    }
};
