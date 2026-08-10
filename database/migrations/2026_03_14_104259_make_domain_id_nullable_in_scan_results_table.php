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
        Schema::table('scan_results', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->foreignId('domain_id')->nullable()->change()->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scan_results', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->foreignId('domain_id')->nullable(false)->change()->constrained()->cascadeOnDelete();
        });
    }
};
