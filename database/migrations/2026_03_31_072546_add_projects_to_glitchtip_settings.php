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
        Schema::table('glitch_tip_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('glitch_tip_settings', 'projects')) {
                $table->json('projects')->nullable()->after('org_slug');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('glitch_tip_settings', function (Blueprint $table) {
            $table->dropColumn('projects');
        });
    }
};
