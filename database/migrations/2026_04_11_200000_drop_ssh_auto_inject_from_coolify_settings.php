<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->dropColumn(['admin_app_uuid', 'ssh_auto_inject_pending', 'ssh_pre_deploy_command_backup']);
        });
    }

    public function down(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->string('admin_app_uuid', 255)->nullable()->after('primary_proxy_uuid');
            $table->boolean('ssh_auto_inject_pending')->default(false)->after('ssh_test_status');
            $table->text('ssh_pre_deploy_command_backup')->nullable()->after('ssh_auto_inject_pending');
        });
    }
};
