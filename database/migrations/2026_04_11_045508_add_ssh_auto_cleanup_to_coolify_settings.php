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
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->boolean('ssh_auto_cleanup_enabled')->default(false)->after('ssh_test_status');
            $table->integer('ssh_auto_cleanup_threshold')->default(80)->after('ssh_auto_cleanup_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->dropColumn(['ssh_auto_cleanup_enabled', 'ssh_auto_cleanup_threshold']);
        });
    }
};
