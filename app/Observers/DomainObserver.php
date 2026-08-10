<?php

namespace App\Observers;

use App\Jobs\SyncCoolifyDomains;
use App\Models\Domain;
use App\Services\EnsureDefaultCookieGroups;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DomainObserver
{
    /**
     * Handle the Domain "created" event.
     */
    public function created(Domain $domain): void
    {
        activity()
            ->performedOn($domain)
            ->causedBy(auth()->user())
            ->log("Domain created: {$domain->name}");

        if ($domain->group_id) {
            EnsureDefaultCookieGroups::attachAllToDomain($domain);
        }

        // Dispatch debounced batch sync to Coolify
        SyncCoolifyDomains::dispatch();
    }

    /**
     * Fields that affect the proxy config served to the Node proxy.
     * ONLY changes to these fields should bump config_version and trigger
     * cache invalidation. All other fields (timestamps, health status, etc.)
     * are observer-safe — they don't affect how the proxy serves traffic.
     */
    protected const PROXY_CONFIG_FIELDS = [
        // Identity & routing
        'name', 'site_id', 'is_active',
        // Origin settings
        'origin_url', 'origin_ip', 'origin_subdomain', 'origin_host',
        'origin_auth_token', 'origin_auth_token_legacy', 'origin_auth_legacy_expires_at',
        // Proxy control
        'proxy_enabled', 'proxy_status',
        // Consent settings
        'consent_mode_enabled', 'advanced_consent_mode', 'consent_version',
        // Feature flags
        'geo_restriction_eu', 'manifest_enabled',
        // Theme / cookie bar
        'theme_settings', 'cookie_bar_id',
        // Proxy rate limiting
        'rate_limit_enabled', 'rate_limit_max_requests_per_minute', 'rate_limit_exclude_paths',
        // Runtime auto-blocking toggles
        'auto_blocking',
        // Fallback content blocker selection
        'fallback_content_blocker_id',
    ];

    public function updated(Domain $domain): void
    {
        activity()
            ->performedOn($domain)
            ->causedBy(auth()->user())
            ->log("Domain updated: {$domain->name}");

        // Only bump config_version when proxy-relevant fields actually changed.
        // This prevents scheduled tasks (VerifyProxyDns, RunAutoHealthChecks, etc.)
        // from causing constant config churn and container restarts.
        if ($domain->wasChanged(self::PROXY_CONFIG_FIELDS)) {
            // Bump config_version and notify Node proxy via Redis
            $this->bumpConfigVersion($domain);

            // Trigger manifest recompilation (runs alongside legacy path during migration)
            if ($domain->manifest_enabled) {
                \App\Jobs\CompileAndPublishRevision::dispatch($domain->id, auth()->id());
            }
        }

        // Dispatch debounced batch sync to Coolify if routing config changed
        if ($domain->wasChanged(['is_active', 'name', 'proxy_enabled'])) {
            SyncCoolifyDomains::dispatch();
            
            // Push active status to Improve Ypsilon without blocking the UI
            dispatch(fn() => \App\Services\TelemetryService::syncDomain($domain));
        }
    }

    public function deleted(Domain $domain): void
    {
        activity()
            ->performedOn($domain)
            ->causedBy(auth()->user())
            ->log("Domain deleted: {$domain->name}");

        // Flush Laravel-side proxy config cache
        Cache::forget("proxy_config:{$domain->name}");

        // Flush Node Proxy's derived Redis cache
        // Without this, deleted domains continue being served from
        // stale proxy_cfg:{host} for up to CACHE_TTL + MAX_STALE_MS (~15 min).
        try {
            Redis::connection('default')->del("proxy_cfg:{$domain->name}");
        } catch (\Throwable $e) {
            Log::warning("Redis cleanup failed for deleted domain {$domain->name}: {$e->getMessage()}");
        }

        // Publish full invalidation to Node proxy via Pub/Sub
        // action:'invalidated' tells the proxy to clear RAM, Redis, AND disk snapshot.
        try {
            $payload = json_encode([
                'host' => $domain->name,
                'version' => null,
                'action' => 'invalidated',
            ]);
            Redis::connection('pubsub')->publish('domain-config-updated', $payload);
        } catch (\Throwable $e) {
            Log::warning("Redis publish failed for deleted domain {$domain->name}: {$e->getMessage()}");
        }

        // Dispatch debounced batch sync to Coolify
        SyncCoolifyDomains::dispatch();
    }

    /**
     * Increment config_version and publish Redis invalidation event.
     *
     * Public entry point for external services (e.g. PublishTrigger) that
     * need to invalidate a domain's proxy config when related models change.
     */
    public function forceBumpConfigVersion(Domain $domain): void
    {
        $this->bumpConfigVersion($domain);
    }

    /**
     * Increment config_version and publish Redis invalidation event.
     *
     * Uses a raw DB::table increment to avoid any model lifecycle,
     * observer recursion, or double-bump risk. This is a single
     * atomic UPDATE ... SET config_version = config_version + 1.
     */
    protected function bumpConfigVersion(Domain $domain): void
    {
        // Atomic DB increment — no model save, no observer re-entry
        DB::table('domains')
            ->where('id', $domain->id)
            ->increment('config_version');

        // Read back the new version for the Redis message
        $newVersion = DB::table('domains')
            ->where('id', $domain->id)
            ->value('config_version');

        // PRE-WARM CACHES (PUSH ARCHITECTURE)
        try {
            // Instantiate controller to reuse exact config-building logic
            $controller = app(\App\Http\Controllers\Api\ProxyConfigController::class);
            $config = $controller->buildConfig($domain->name);

            if ($config) {
                // Ensure the revision matches our explicit atomic bump
                $config['revision'] = $newVersion.':'.crc32(config('app.url'));

                // 1. Warm Laravel cache
                Cache::put("proxy_config:{$domain->name}", $config, 60);

                // 2. Warm Node Proxy Redis directly (Raw JSON string)
                // This eliminates the proxy taking an HTTP 1500ms hit.
                Redis::connection('default')->setex(
                    "proxy_cfg:{$domain->name}",
                    3600,
                    json_encode($config)
                );
            } else {
                // Config was nulled (e.g. proxy disabled), clear it
                Cache::forget("proxy_config:{$domain->name}");
                Redis::connection('default')->del("proxy_cfg:{$domain->name}");
            }
        } catch (\Throwable $e) {
            Log::error("Failed to pre-warm config for domain {$domain->name}: {$e->getMessage()}");
            // Fallback to old invalidate-only behavior
            Cache::forget("proxy_config:{$domain->name}");
        }

        // 3. Publish to Redis for Node proxy real-time in-memory invalidation.
        // Use action:'pushed' when config exists (Node keeps Redis, clears RAM + disk).
        // Use action:'invalidated' when config is null (Node clears ALL derived layers).
        try {
            $payload = json_encode([
                'host' => $domain->name,
                'version' => $newVersion,
                'action' => $config ? 'pushed' : 'invalidated',
            ]);
            Redis::connection('pubsub')->publish('domain-config-updated', $payload);
        } catch (\Throwable $e) {
            // Redis failure should not break the admin panel
            Log::warning("Redis publish failed for domain {$domain->name}: {$e->getMessage()}");
        }
    }
}
