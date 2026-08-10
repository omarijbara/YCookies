<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_page_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('set_index');
            $table->json('pages');
            $table->unsignedInteger('page_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
            $table->foreignId('scan_result_id')->nullable()->constrained('scan_results')->nullOnDelete();
            $table->unsignedInteger('cycle_number')->default(1);
            $table->timestamps();

            $table->index(['domain_id', 'cycle_number', 'set_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_page_sets');
    }
};
