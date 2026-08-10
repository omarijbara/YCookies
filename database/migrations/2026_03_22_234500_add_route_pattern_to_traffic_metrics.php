<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add route_pattern column to traffic_metrics for cardinality-controlled
 * per-route aggregation.
 *
 * Creates new composite unique on (domain_id, bucket, route_pattern).
 * The old (domain_id, bucket) unique is left in place because MySQL
 * may use it as the backing index for the domain_id FK constraint.
 * Both indexes coexist harmlessly — the new one is more specific.
 *
 * Fully idempotent: safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add route_pattern column if missing
        if (!Schema::hasColumn('traffic_metrics', 'route_pattern')) {
            Schema::table('traffic_metrics', function (Blueprint $table) {
                $table->string('route_pattern', 255)
                    ->nullable()
                    ->default(null)
                    ->comment('Fingerprinted route, e.g. /checkout/:id');
            });
        }

        // 2. Add new composite unique if missing
        //    (old unique left in place — MySQL FK dependency)
        if (!$this->indexExists('traffic_metrics', 'traffic_metrics_domain_bucket_route_unique')) {
            Schema::table('traffic_metrics', function (Blueprint $table) {
                $table->unique(
                    ['domain_id', 'bucket', 'route_pattern'],
                    'traffic_metrics_domain_bucket_route_unique'
                );
            });
        }

        // 3. Add route_pattern index if missing
        if (!$this->indexExists('traffic_metrics', 'traffic_metrics_route_pattern_index')) {
            Schema::table('traffic_metrics', function (Blueprint $table) {
                $table->index('route_pattern');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('traffic_metrics', 'traffic_metrics_domain_bucket_route_unique')) {
            Schema::table('traffic_metrics', function (Blueprint $table) {
                $table->dropUnique('traffic_metrics_domain_bucket_route_unique');
            });
        }

        if ($this->indexExists('traffic_metrics', 'traffic_metrics_route_pattern_index')) {
            Schema::table('traffic_metrics', function (Blueprint $table) {
                $table->dropIndex(['route_pattern']);
            });
        }

        if (Schema::hasColumn('traffic_metrics', 'route_pattern')) {
            Schema::table('traffic_metrics', function (Blueprint $table) {
                $table->dropColumn('route_pattern');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        // Database-agnostic: works on MySQL, SQLite, PostgreSQL
        if (method_exists(Schema::class, 'getIndexes')) {
            // Laravel 11+
            $indexes = Schema::getIndexes($table);
            foreach ($indexes as $index) {
                if ($index['name'] === $indexName) {
                    return true;
                }
            }
            return false;
        }

        // Fallback for older Laravel: use doctrine/dbal
        try {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes($table);
            return isset($indexes[$indexName]);
        } catch (\Throwable) {
            return false;
        }
    }
};
