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
        Schema::create('cookie_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('key')->index(); // e.g., 'essential', 'marketing'
            $table->string('name'); // e.g., 'Essential', 'Marketing'
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false); // Essential cookies cannot be unchecked
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['domain_id', 'key']); // A domain can only have one group per key
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cookie_groups');
    }
};
