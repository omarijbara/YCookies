<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->foreignId('fallback_content_blocker_id')
                ->nullable()
                ->after('auto_blocking')
                ->constrained('content_blockers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropForeign(['fallback_content_blocker_id']);
            $table->dropColumn('fallback_content_blocker_id');
        });
    }
};
