<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Set all domains to use the Node proxy engine.
     * The Laravel proxy engine has been removed.
     */
    public function up(): void
    {
        DB::table('domains')->update(['proxy_engine' => 'node']);
    }

    /**
     * No rollback — the Laravel proxy engine code has been deleted.
     */
    public function down(): void
    {
        // Column stays, but the Laravel proxy engine code no longer exists.
    }
};
