<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('origin_url')->nullable()->after('name');
            $table->boolean('proxy_enabled')->default(false)->after('origin_url');
            $table->string('proxy_status')->nullable()->after('proxy_enabled'); // pending, active, dns_error, ssl_pending
            $table->timestamp('proxy_verified_at')->nullable()->after('proxy_status');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['origin_url', 'proxy_enabled', 'proxy_status', 'proxy_verified_at']);
        });
    }
};
