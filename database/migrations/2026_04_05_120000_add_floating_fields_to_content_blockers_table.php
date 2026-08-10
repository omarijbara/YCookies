<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blockers', function (Blueprint $table) {
            $table->string('display_mode')->default('inline')->after('is_active');
            $table->string('floating_position')->nullable()->after('display_mode');
            $table->string('floating_icon_url')->nullable()->after('floating_position');
            $table->string('floating_label')->nullable()->after('floating_icon_url');
            $table->string('design_template')->nullable()->after('floating_label');
        });
    }

    public function down(): void
    {
        Schema::table('content_blockers', function (Blueprint $table) {
            $table->dropColumn([
                'display_mode',
                'floating_position',
                'floating_icon_url',
                'floating_label',
                'design_template',
            ]);
        });
    }
};
