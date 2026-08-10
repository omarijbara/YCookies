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
            $table->integer('ssh_auto_cleanup_interval')->default(60)->after('ssh_auto_cleanup_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->dropColumn('ssh_auto_cleanup_interval');
        });
    }
};
