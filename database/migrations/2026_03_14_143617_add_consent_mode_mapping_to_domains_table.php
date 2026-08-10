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
            $table->boolean('consent_mode_enabled')->default(true)->after('consent_version');
            $table->json('consent_mode_mapping')->nullable()->after('consent_mode_enabled');
        });

        // Set sensible defaults for existing domains
        \App\Models\Domain::withoutGlobalScopes()->update([
            'consent_mode_mapping' => json_encode([
                'ad_storage' => 'marketing',
                'ad_user_data' => 'marketing',
                'ad_personalization' => 'marketing',
                'analytics_storage' => 'statistics',
                'functionality_storage' => 'statistics',
                'personalization_storage' => 'marketing',
                'security_storage' => 'statistics',
            ]),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['consent_mode_enabled', 'consent_mode_mapping']);
        });
    }
};
