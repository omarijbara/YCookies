<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('script_blockers', function (Blueprint $table) {
            $table->string('template_key')->nullable()->after('key');
        });

        Schema::table('content_blockers', function (Blueprint $table) {
            $table->string('template_key')->nullable()->after('key');
        });
    }

    public function down(): void
    {
        Schema::table('script_blockers', function (Blueprint $table) {
            $table->dropColumn('template_key');
        });

        Schema::table('content_blockers', function (Blueprint $table) {
            $table->dropColumn('template_key');
        });
    }
};
