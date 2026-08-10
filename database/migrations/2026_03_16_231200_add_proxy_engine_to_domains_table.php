<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('proxy_engine', 16)
                ->default('laravel')
                ->after('proxy_verified_at')
                ->comment('Canary routing control: laravel or node');
        });

        // Backfill existing rows to 'laravel'
        DB::table('domains')
            ->whereNull('proxy_engine')
            ->update(['proxy_engine' => 'laravel']);
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('proxy_engine');
        });
    }
};
