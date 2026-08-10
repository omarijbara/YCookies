<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename the 'domains' JSON column on services table to 'service_domains'
     * to resolve the naming collision with the Service::domains() BelongsToMany relationship.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('domains', 'service_domains');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('service_domains', 'domains');
        });
    }
};
