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
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_key_unique');
            $table->unique(['group_id', 'key']);
        });

        Schema::table('content_blockers', function (Blueprint $table) {
            $table->dropUnique('content_blockers_key_unique');
            $table->unique(['group_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['group_id', 'key']);
            $table->string('key')->unique()->change();
        });

        Schema::table('content_blockers', function (Blueprint $table) {
            $table->dropUnique(['group_id', 'key']);
            $table->string('key')->unique()->change();
        });
    }
};
