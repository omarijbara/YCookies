<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the old (domain_id, bucket) unique constraint that blocks
 * per-route aggregation. MySQL won't drop a unique index that backs
 * a FK, so we drop the FK first, drop the old unique, then readd
 * the FK with a plain index.
 *
 * Idempotent: safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only run if the old unique index still exists
        if (!$this->indexExists('traffic_metrics', 'traffic_metrics_domain_bucket_unique')) {
            echo "  Old unique index already removed — skipping.\n";
            return;
        }

        Schema::table('traffic_metrics', function (Blueprint $table) {
            // 1. Drop FK that depends on the old unique
            //    Laravel convention: table_column_foreign
            $fkName = $this->findForeignKeyOnColumn('traffic_metrics', 'domain_id');
            if ($fkName) {
                $table->dropForeign(['domain_id']);
            }
        });

        Schema::table('traffic_metrics', function (Blueprint $table) {
            // 2. Drop the old unique index
            $table->dropUnique('traffic_metrics_domain_bucket_unique');
        });

        Schema::table('traffic_metrics', function (Blueprint $table) {
            // 3. Add a plain index for domain_id (FK needs one)
            if (!$this->indexExists('traffic_metrics', 'traffic_metrics_domain_id_index')) {
                $table->index('domain_id', 'traffic_metrics_domain_id_index');
            }

            // 4. Re-add FK on the plain index
            $table->foreign('domain_id')
                ->references('id')
                ->on('domains')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Reverse: re-add old unique, drop plain index
        Schema::table('traffic_metrics', function (Blueprint $table) {
            $fkName = $this->findForeignKeyOnColumn('traffic_metrics', 'domain_id');
            if ($fkName) {
                $table->dropForeign(['domain_id']);
            }
        });

        Schema::table('traffic_metrics', function (Blueprint $table) {
            if ($this->indexExists('traffic_metrics', 'traffic_metrics_domain_id_index')) {
                $table->dropIndex('traffic_metrics_domain_id_index');
            }

            if (!$this->indexExists('traffic_metrics', 'traffic_metrics_domain_bucket_unique')) {
                $table->unique(['domain_id', 'bucket'], 'traffic_metrics_domain_bucket_unique');
            }

            $table->foreign('domain_id')
                ->references('id')
                ->on('domains')
                ->cascadeOnDelete();
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: check pragma index_list
            $indexes = DB::select("PRAGMA index_list(`{$table}`)");
            foreach ($indexes as $idx) {
                if ($idx->name === $indexName) return true;
            }
            return false;
        }

        return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName])) > 0;
    }

    private function findForeignKeyOnColumn(string $table, string $column): ?string
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite FK names follow Laravel convention: table_column_foreign
            return "{$table}_{$column}_foreign";
        }

        $db = config('database.connections.mysql.database', 'ycookies');
        $results = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$db, $table, $column]
        );
        return $results[0]->CONSTRAINT_NAME ?? null;
    }
};
