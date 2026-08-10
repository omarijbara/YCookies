<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `group_user` pivot table for many-to-many user↔group membership.
 *
 * This is the foundation of tenant isolation: a user can only access
 * groups they are explicitly assigned to via this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50)->default('member'); // member, admin, owner
            $table->timestamps();

            $table->unique(['user_id', 'group_id']);
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_user');
    }
};
