<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Runtime\ManifestMetrics;
use Illuminate\Console\Command;

/**
 * CLI dashboard for manifest runtime metrics.
 *
 * Usage:
 *   php artisan manifest:metrics           # Table view
 *   php artisan manifest:metrics --json    # JSON output
 *   php artisan manifest:metrics --reset   # Reset all counters
 */
class ManifestMetricsCommand extends Command
{
    protected $signature = 'manifest:metrics
                            {--json : Output as JSON}
                            {--reset : Reset all counters}
                            {--emit-log : Emit metrics to structured log}';

    protected $description = 'Show manifest runtime metrics (verification, cache, Redis)';

    public function handle(): int
    {
        if ($this->option('reset')) {
            ManifestMetrics::reset();
            $this->info('✓ All manifest metrics counters reset.');
            return self::SUCCESS;
        }

        if ($this->option('emit-log')) {
            ManifestMetrics::emitToLog();
            $this->info('✓ Metrics emitted to structured log.');
            return self::SUCCESS;
        }

        $metrics = ManifestMetrics::all();

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('═══ Manifest Runtime Metrics ═══');
        $this->newLine();

        // Verification
        $this->info('Signature Verification');
        $this->table(['Metric', 'Value'], [
            ['Successes', $metrics['verification_successes']],
            ['Failures', $metrics['verification_failures']],
            ['Missing Signature', $metrics['verification_missing_signature']],
        ]);

        // Resolver Cache
        $this->info('Resolver Cache');
        $this->table(['Metric', 'Value'], [
            ['Cache Hits', $metrics['resolver_cache_hits']],
            ['Cache Misses', $metrics['resolver_cache_misses']],
            ['Hit Ratio', $metrics['resolver_cache_hit_ratio'] . '%'],
            ['Invalidations', $metrics['invalidations']],
        ]);

        // Publish / Redis
        $this->info('Publish & Redis');
        $this->table(['Metric', 'Value'], [
            ['Published Unverified', $metrics['publish_unverified']],
            ['Redis Mirror Failures', $metrics['redis_mirror_failures']],
            ['Redis Pub/Sub Failures', $metrics['redis_pubsub_failures']],
        ]);

        // Alerts
        if ($metrics['verification_failures'] > 0) {
            $this->error("⚠ {$metrics['verification_failures']} verification failure(s) detected. Check logs for details.");
        }

        if ($metrics['publish_unverified'] > 0) {
            $this->warn("⚠ {$metrics['publish_unverified']} published_unverified revision(s). Review manually.");
        }

        if ($metrics['redis_mirror_failures'] > 0 || $metrics['redis_pubsub_failures'] > 0) {
            $this->warn("⚠ Redis write failures detected. Check Redis connectivity.");
        }

        return self::SUCCESS;
    }
}
