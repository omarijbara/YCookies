<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->json('auto_blocking')->nullable()->after('rate_limit_exclude_paths');
        });

        $defaultConfig = json_encode([
            'content' => true,
            'script' => true,
            'style' => true,
            'service' => true,
        ], JSON_UNESCAPED_SLASHES);

        DB::table('domains')
            ->whereNull('auto_blocking')
            ->update(['auto_blocking' => $defaultConfig]);
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('auto_blocking');
        });
    }
};
