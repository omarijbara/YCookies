<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('script_blockers', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
            $table->json('hosts')->nullable()->after('phrases');
            $table->string('require_group')->nullable()->after('hosts');
        });

        // Make domain_id nullable so universal (tenant-level) blockers can exist
        // without being tied to a specific domain — mirrors ContentBlocker pattern.
        Schema::table('script_blockers', function (Blueprint $table) {
            $table->unsignedBigInteger('domain_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('script_blockers', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'hosts', 'require_group']);
        });
    }
};
