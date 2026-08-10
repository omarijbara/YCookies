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
            $table->boolean('rate_limit_enabled')
                ->default(true)
                ->after('proxy_status');

            $table->integer('rate_limit_max_requests_per_minute')
                ->default(200)
                ->after('rate_limit_enabled');

            $table->json('rate_limit_exclude_paths')
                ->nullable()
                ->after('rate_limit_max_requests_per_minute');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'rate_limit_enabled',
                'rate_limit_max_requests_per_minute',
                'rate_limit_exclude_paths',
            ]);
        });
    }
};
