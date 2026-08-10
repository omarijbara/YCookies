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
        Schema::table('content_blockers', function (Blueprint $table) {
            $table->string('privacy_policy_url')->nullable()->after('preview_image_url');
            $table->text('html_code')->nullable()->after('privacy_policy_url');
            $table->text('css_code')->nullable()->after('html_code');
            $table->text('js_code')->nullable()->after('css_code');
            $table->json('text_placeholders')->nullable()->after('js_code');
            $table->foreignId('provider_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_blockers', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropColumn([
                'privacy_policy_url',
                'html_code',
                'css_code',
                'js_code',
                'text_placeholders',
                'provider_id',
            ]);
        });
    }
};
