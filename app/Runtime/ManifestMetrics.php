<?php

declare(strict_types=1);

namespace App\Runtime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ManifestMetrics — Lightweight counter-based metrics for manifest runtime.
 *
 * Uses Laravel Cache (Redis-backed) for atomic increment counters.
 * Counters persist across requests and are namespaced under 'manifest_metrics:'.
 *
 * Consumed by:
 *   - php artisan manifest:metrics             (CLI dashboard)
 *   - /statsz endpoint (if wired)              (HTTP dashboard)
 *   - Structured log output for log aggregators
 *
 * Counter categories:
 *   - verification_failures    Ed25519 verification failed at read time
 *   - verification_successes   Ed25519 verification succeeded
 *   - sentinel_active          Domains currently in sentinel (verification-failed) state
 *   - resolver_cache_hits      Resolver returned from cache
 *   - resolver_cache_misses    Resolver fell through to DB
 *   - invalidations            Cache invalidations triggered
 *   - publish_unverified       Revisions published but failing post-publish verify
 *   - redis_mirror_failures    postPublishAccelerate Redis setex failures
 *   - redis_pubsub_failures    postPublishAccelerate pub/sub failures
 *   - proxy_warm_failures      postPublishAccelerate endpoint cache-warm failures
 */
class ManifestMetrics
{
    private const PREFIX = 'manifest_metrics:';

    /**
     * Increment a counter.
     */
    public static function increment(string $counter, int $by = 1): void
    {
        try {
            $key = self::PREFIX . $counter;
            if (!Cache::has($key)) {
                Cache::put($key, 0, 86400);
            }
            Cache::increment($key, $by);
        } catch (\Throwable $e) {
            // Metrics are never fatal
            Log::debug("ManifestMetrics: failed to increment {$counter}: {$e->getMessage()}");
        }
    }

    /**
     * Decrement a counter (gauge-safe, floors at 0).
     */
    public static function decrement(string $counter, int $by = 1): void
    {
        try {
            $key = self::PREFIX . $counter;
            if (!Cache::has($key)) {
                Cache::put($key, 0, 86400);
                return;
            }
            $current = (int) Cache::get($key, 0);
            Cache::put($key, max(0, $current - $by), 86400);
        } catch (\Throwable $e) {
            Log::debug("ManifestMetrics: failed to decrement {$counter}: {$e->getMessage()}");
        }
    }

    /**
     * Set a gauge value (for point-in-time values like sentinel_active).
     */
    public static function gauge(string $name, int $value): void
    {
        try {
            Cache::put(self::PREFIX . $name, $value, 3600);
        } catch (\Throwable $e) {
            Log::debug("ManifestMetrics: failed to set gauge {$name}: {$e->getMessage()}");
        }
    }

    /**
     * Get a counter or gauge value.
     */
    public static function get(string $counter): int
    {
        try {
            return (int) Cache::get(self::PREFIX . $counter, 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get all metrics as an associative array.
     */
    public static function all(): array
    {
        $counters = [
            'verification_failures',
            'verification_successes',
            'verification_missing_signature',
            'sentinel_active',
            'resolver_cache_hits',
            'resolver_cache_misses',
            'invalidations',
            'publish_unverified',
            'redis_mirror_failures',
            'redis_pubsub_failures',
            'proxy_warm_failures',
        ];

        $result = [];
        foreach ($counters as $counter) {
            $result[$counter] = self::get($counter);
        }

        // Derived metrics
        $total = $result['resolver_cache_hits'] + $result['resolver_cache_misses'];
        $result['resolver_cache_hit_ratio'] = $total > 0
            ? round($result['resolver_cache_hits'] / $total * 100, 1)
            : 0.0;

        return $result;
    }

    /**
     * Reset all counters (for testing or operator use).
     */
    public static function reset(): void
    {
        $counters = [
            'verification_failures',
            'verification_successes',
            'verification_missing_signature',
            'sentinel_active',
            'resolver_cache_hits',
            'resolver_cache_misses',
            'invalidations',
            'publish_unverified',
            'redis_mirror_failures',
            'redis_pubsub_failures',
            'proxy_warm_failures',
        ];

        foreach ($counters as $counter) {
            try {
                Cache::forget(self::PREFIX . $counter);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Emit all metrics as a structured log line for log aggregators.
     */
    public static function emitToLog(): void
    {
        Log::info('manifest_metrics', self::all());
    }
}
