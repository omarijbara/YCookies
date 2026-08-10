<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->text('ssh_private_key')->nullable()->after('primary_proxy_uuid');
            $table->text('ssh_public_key')->nullable()->after('ssh_private_key');
            $table->string('ssh_host', 255)->nullable()->after('ssh_public_key');
            $table->unsignedSmallInteger('ssh_port')->default(22)->after('ssh_host');
            $table->string('ssh_user', 64)->default('root')->after('ssh_port');
            $table->boolean('ssh_is_active')->default(false)->after('ssh_user');
            $table->timestamp('ssh_tested_at')->nullable()->after('ssh_is_active');
            $table->string('ssh_test_status', 20)->nullable()->after('ssh_tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ssh_private_key',
                'ssh_public_key',
                'ssh_host',
                'ssh_port',
                'ssh_user',
                'ssh_is_active',
                'ssh_tested_at',
                'ssh_test_status',
            ]);
        });
    }
};
