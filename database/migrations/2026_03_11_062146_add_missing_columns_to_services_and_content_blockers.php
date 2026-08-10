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
            $table->integer('sort_order')->default(0)->after('is_active');
        });

        Schema::table('content_blockers', function (Blueprint $table) {
            $table->json('hosts')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });

        Schema::table('content_blockers', function (Blueprint $table) {
            $table->dropColumn('hosts');
        });
    }
};
