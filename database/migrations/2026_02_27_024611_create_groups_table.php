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
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $tablesToUpdate = ['domains', 'cookie_bars', 'cookie_groups', 'services', 'content_blockers'];

        foreach ($tablesToUpdate as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tablesToUpdate = ['domains', 'cookie_bars', 'cookie_groups', 'services', 'content_blockers'];

        foreach ($tablesToUpdate as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['group_id']);
                $table->dropColumn('group_id');
            });
        }

        Schema::dropIfExists('groups');
    }
};
