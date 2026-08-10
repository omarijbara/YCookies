<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix dedup unique constraint on traffic_alert_states.
 *
 * Problem: unique(domain_id, alert_type, state) prevents state transitions
 * when a resolved row already exists for the same domain+type.
 *
 * Fix: change to unique(domain_id, alert_type).
 *
 * Complication: MySQL uses the unique index to back the FK on domain_id.
 * We must drop the FK first, then the index, then re-add both.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Remove duplicate rows — keep the most recently fired for each domain_id + alert_type
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('
                DELETE FROM traffic_alert_states
                WHERE id NOT IN (
                    SELECT max_id FROM (
                        SELECT MAX(id) as max_id FROM traffic_alert_states GROUP BY domain_id, alert_type
                    ) as t
                )
            ');
        } else {
            DB::statement('
                DELETE t1 FROM traffic_alert_states t1
                INNER JOIN traffic_alert_states t2
                WHERE t1.domain_id = t2.domain_id
                  AND t1.alert_type = t2.alert_type
                  AND t1.id < t2.id
            ');
        }

        Schema::table('traffic_alert_states', function (Blueprint $table) {
            // Drop FK that depends on the index (only on MySQL/Postgres)
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['domain_id']);
                // Drop old unique that includes state
                $table->dropUnique('traffic_alerts_dedup');
            }

            // Add new unique on domain_id + alert_type only
            // SQLite constraints might just add the new index rather than dropping properly without table rebuild, 
            // but Laravel handles it reasonably via Doctrine DBAL.
            if (DB::getDriverName() !== 'sqlite') {
                $table->unique(['domain_id', 'alert_type'], 'traffic_alerts_dedup_new');
                // Restore the FK
                $table->foreign('domain_id')->references('id')->on('domains')->cascadeOnDelete();
            } else {
                $table->unique(['domain_id', 'alert_type'], 'traffic_alerts_dedup_new');
            }
        });
    }

    public function down(): void
    {
        Schema::table('traffic_alert_states', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropUnique('traffic_alerts_dedup');
            $table->unique(['domain_id', 'alert_type', 'state'], 'traffic_alerts_dedup');
            $table->foreign('domain_id')->references('id')->on('domains')->cascadeOnDelete();
        });
    }
};
