<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->json('priority_pages')->nullable()->after('scan_pages');
            $table->json('auto_priority_pages')->nullable()->after('priority_pages');
            $table->unsignedInteger('discovered_pages_count')->default(0)->after('auto_priority_pages');
            $table->unsignedInteger('current_set_index')->default(0)->after('discovered_pages_count');
            $table->unsignedInteger('current_cycle')->default(1)->after('current_set_index');
            $table->timestamp('last_discovery_at')->nullable()->after('current_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'priority_pages',
                'auto_priority_pages',
                'discovered_pages_count',
                'current_set_index',
                'current_cycle',
                'last_discovery_at',
            ]);
        });
    }
};
