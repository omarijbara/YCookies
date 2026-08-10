<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('script_blockers', function (Blueprint $table) {
            $table->string('blocker_type')->default('script')->after('template_version');
        });

        DB::table('script_blockers')
            ->whereNull('blocker_type')
            ->update(['blocker_type' => 'script']);
    }

    public function down(): void
    {
        Schema::table('script_blockers', function (Blueprint $table) {
            $table->dropColumn('blocker_type');
        });
    }
};
