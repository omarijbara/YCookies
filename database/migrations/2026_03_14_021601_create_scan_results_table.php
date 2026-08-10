<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('domain_name');
            $table->timestamp('scanned_at');
            $table->string('source')->default('manual'); // manual, auto, scheduled
            $table->integer('total_scripts')->default(0);
            $table->integer('protected_count')->default(0);
            $table->integer('suggested_count')->default(0);
            $table->integer('unknown_count')->default(0);
            $table->integer('pages_scanned_count')->default(0);
            $table->json('pages_scanned')->nullable();
            $table->json('scan_log')->nullable();
            $table->json('scan_stages')->nullable();
            $table->json('protected_scripts')->nullable();
            $table->json('suggested_scripts')->nullable();
            $table->json('unknown_scripts')->nullable();
            $table->json('raw_scripts')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_results');
    }
};
