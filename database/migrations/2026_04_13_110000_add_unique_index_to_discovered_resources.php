<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Delete duplicates, keeping the one with highest hit_count (or max id)
        // using a temporary subquery to find records to keep
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('
                DELETE t1 FROM discovered_resources t1
                INNER JOIN discovered_resources t2
                WHERE t1.id < t2.id
                AND t1.domain_id = t2.domain_id
                AND t1.provider_host = t2.provider_host
                AND t1.resource_type = t2.resource_type
            ');
        }

        Schema::table('discovered_resources', function (Blueprint $table) {
            $table->unique(['domain_id', 'provider_host', 'resource_type'], 'disc_res_domain_host_type_unique');
            $table->index(['domain_id', 'status'], 'disc_res_domain_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('discovered_resources', function (Blueprint $table) {
            $table->dropUnique('disc_res_domain_host_type_unique');
            $table->dropIndex('disc_res_domain_status_index');
        });
    }
};
