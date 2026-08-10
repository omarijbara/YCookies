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
        Schema::create('service_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->text('opt_in_code')->nullable(); // e.g. `<script>gtag('config'...)</script>`
            $table->text('opt_out_code')->nullable();
            $table->text('fallback_code')->nullable(); // Executed on the server side or before consent
            $table->timestamps();

            $table->unique('service_id'); // One-to-One relationship with Service
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_settings');
    }
};
