/**
 * Laravel Client — Config fetcher with HMAC verification, multi-tier cache,
 * and state reconciliation guarantees.
 *
 * Reconciliation contract:
 *   - MySQL is the single source of truth
 *   - Redis proxy_cfg:{host} is a derived push-cache
 *   - RAM cache is a derived ephemeral cache with bounded staleness
 *   - Pub/Sub is an acceleration mechanism, NOT the source of truth
 *   - No lifecycle event requires "event delivery success" to remain correct
 *
 * Cache tiers (in order of preference):
 *   1. RAM   — instant, 5min TTL, bounded by MAX_STALE_MS
 *   2. Redis — ~1ms, 1h TTL, revision-validated against RAM
 *   3. HTTP  — ~50-200ms, authoritative fetch from Laravel/MySQL
 *   4. Redis grace — stale Redis for disaster recovery
 *   5. Disk snapshot — last resort, survives container restarts
 *
 * Per-host single-flight ensures concurrent cache misses collapse
 * into one HTTP fetch. Bounded staleness (MAX_STALE_MS) ensures
 * missed Pub/Sub cannot cause indefinite stale state.
 */

import { request as undiciRequest } from "undici";
import { join } from "node:path";
import { readdirSync } from "node:fs";
import Redis from "ioredis";
import { saveSnapshot, deleteSnapshot, loadSnapshot } from "./disk-snapshot.js";
import { signRequest, verifySignature } from "./config-verifier.js";

const LARAVEL_URL = process.env.LARAVEL_URL || "http://127.0.0.1:8000";
const SHARED_SECRET = process.env.PROXY_SHARED_SECRET || "";
const SHARED_SECRET_PREV = process.env.PROXY_SHARED_SECRET_PREV || "";
const CACHE_TTL = parseInt(process.env.CONFIG_CACHE_TTL || "300", 10) * 1000; // ms
const MAX_STALE_MS = parseInt(process.env.CONFIG_MAX_STALE || "600", 10) * 1000; // 10 min absolute staleness bound
const REDIS_URL = process.env.REDIS_URL || "redis://app-redis:6379";
const SNAPSHOT_DIR = process.env.CONFIG_SNAPSHOT_DIR || "/data/config-cache";

/**
 * Add ±20% random jitter to a TTL to desynchronize cache expiry
 * across hosts. Prevents cross-host stampede after restart or
 * burst invalidation.
 *
 * @param {number} baseTTL - Base TTL in milliseconds
 * @returns {number} Jittered TTL (never less than 1/2 of base)
 */
export function jitteredTTL(baseTTL) {
    const jitter = baseTTL * 0.2 * (Math.random() * 2 - 1);
    return Math.max(Math.round(baseTTL / 2), Math.round(baseTTL + jitter));
}

// Initialize Redis for Grace Mode Fallback
// In testing environments, this may fail gracefully without crashing
const redis = new Redis(REDIS_URL, {
    maxRetriesPerRequest: 2,
    enableOfflineQueue: false, // Do not hang awaiting fetches if Redis is offline
    retryStrategy: (times) => Math.min(times * 100, 3000), // Retry with backoff
    commandTimeout: 3000, // Fail fast on zombie TCP connections (half-open state where enableOfflineQueue: false has no effect)
});
redis.on("error", (err) => {
    // Silent catch so it doesn't crash the proxy if Redis is briefly restarting
});

// When using Docker internal networking, the Host header will be the container name
// (e.g., "jws0sgooccs0488ckkgc0gcs-022217429643") which Laravel rejects.
// LARAVEL_API_HOST overrides the Host header to match what Laravel expects.
const LARAVEL_API_HOST = process.env.LARAVEL_API_HOST || "";

/**
 * In-memory config cache with LRU eviction.
 * Map<hostname, { config, expiresAt, revision, fetchedAt, source }>
 *
 * - fetchedAt: timestamp when config was last fetched from an authoritative source (HTTP)
 * - source: 'http' | 'redis' | 'disk' — where this entry originated
 */
const cache = new Map();

/**
 * Per-host in-flight fetch map for single-flight pattern.
 * Map<hostname, Promise<config|null>>
 * Ensures concurrent cache misses for the same host collapse into one HTTP fetch.
 */
const inFlight = new Map();
const MAX_CONFIG_CACHE = 1000; // Prevent unbounded growth → OOM

/**
 * LRU-bounded cache setter. Evicts oldest entry when at capacity.
 */
function cacheSet(key, value) {
    if (cache.size >= MAX_CONFIG_CACHE && !cache.has(key)) {
        // Delete the first (oldest-inserted) key — Map preserves insertion order
        const oldestKey = cache.keys().next().value;
        cache.delete(oldestKey);
    }
    cache.set(key, value);
}

/**
 * Per-tier metrics counters for /statsz and observability.
 */
const metrics = {
    // Per-tier hit counters
    ramHits: 0,
    redisHits: 0,
    httpFetches: 0,
    // Error and recovery counters
    fetchErrors: 0,
    fallbackHits: 0,
    // Reconciliation counters
    coalescedRequests: 0, // single-flight reuse count
    redisStaleSkips: 0, // Redis had older revision than RAM
    staleBounces: 0, // MAX_STALE_MS forced revalidation
    notModified304: 0, // ETag matched, no body transfer
    invalidations: 0,
    // Stampede tracking
    peakInFlight: 0, // high-water mark of concurrent in-flight hosts
    // Timestamps
    lastFetchAt: null,
    lastInvalidationAt: null,
    lastRevisionServed: null,
};

/**
 * Fallback mechanism: Attempt to load the last known good config from Redis
 * if the Laravel API is down.
 */
async function fallbackToRedis(hostname, originalError) {
    metrics.fetchErrors++;

    // Layer 4: Try Redis grace cache
    try {
        const cachedStr = await redis.get(`proxy_cfg:${hostname}`);
        if (cachedStr) {
            console.warn(
                `[Grace Mode] Laravel API down for ${hostname}. Serving stale config from Redis. Original Error: ${originalError.message}`,
            );
            metrics.fallbackHits++;
            const config = JSON.parse(cachedStr);

            // Temporarily restore to in-memory cache to absorb traffic spikes
            cacheSet(hostname, {
                config,
                expiresAt: Date.now() + jitteredTTL(30000), // 30s jittered cache before re-checking
                revision: config.revision,
                fetchedAt: Date.now(),
                source: "redis",
            });
            return config;
        }
    } catch (redisErr) {
        console.error(
            `[Grace Mode] Redis fallback failed: ${redisErr.message}`,
        );
    }

    // Layer 5: Try disk snapshot (last resort — survives full container restarts)
    const diskConfig = loadSnapshot(hostname);
    if (diskConfig) {
        console.warn(
            `[Disk Snapshot] All backends down for ${hostname}. Serving from disk snapshot.`,
        );
        metrics.fallbackHits++;
        cacheSet(hostname, {
            config: diskConfig,
            expiresAt: Date.now() + jitteredTTL(30000),
            revision: diskConfig.revision,
            fetchedAt: Date.now(),
            source: "disk",
        });
        return diskConfig;
    }

    // All 5 layers exhausted. No config available anywhere.
    throw originalError;
}

/**
 * Save config snapshot to disk for disaster recovery.
 * Best-effort write — not atomic (no temp-file + rename).
 * Sufficient for disaster recovery where exact consistency is not required.
 */
/**
 * Parse the numeric version from a revision string like "42:3847291".
 * Returns the integer version, or 0 if unparseable.
 *
 * INVARIANT: The numeric prefix MUST increase monotonically on every
 * meaningful config change. Redis-vs-RAM staleness detection depends
 * on this ordering. The CRC suffix is for diagnostics only.
 * Laravel's DomainObserver.bumpConfigVersion() enforces this via
 * atomic DB::table('domains')->increment('config_version').
 */
function parseRevisionVersion(revision) {
    if (!revision) return 0;
    const parts = String(revision).split(":");
    return parseInt(parts[0], 10) || 0;
}

/**
 * Check if a cache entry has exceeded the absolute staleness bound.
 * Only applies to entries sourced from non-authoritative tiers (redis, disk).
 * HTTP-sourced entries reset fetchedAt and are only bounded by TTL.
 */
function isStale(entry) {
    if (!entry.fetchedAt) return false;
    if (entry.source === "http") return false; // HTTP = authoritative, trust TTL
    return Date.now() - entry.fetchedAt > MAX_STALE_MS;
}

/**
 * Get domain config from cache or Laravel.
 * Returns null if domain is not proxy-enabled.
 *
 * Failure policy:
 *   - Network/backend errors → degrade to stale fallback (availability-leaning)
 *   - HMAC signature failures → throw (fail-closed, security-leaning)
 *
 * Uses per-host single-flight to collapse concurrent cache misses
 * into one HTTP fetch.
 *
 * @param {string} hostname
 * @returns {Promise<object|null>}
 */

export function preWarmFromDisk() {
    try {
        const fs = require('node:fs');
        const SNAPSHOT_DIR = process.env.CONFIG_SNAPSHOT_DIR || "/data/config-cache";
        const files = fs.readdirSync(SNAPSHOT_DIR).filter(f => f.endsWith(".json"));
        let loaded = 0;
        for (const file of files) {
            const hostname = file.replace(/\.json$/, "");
            const config = loadSnapshot(hostname);
            if (config) {
                cacheSet(hostname, {
                    config,
                    expiresAt: Date.now() + jitteredTTL(CACHE_TTL),
                    revision: config.revision,
                    fetchedAt: Date.now(),
                    source: "disk",
                });
                loaded++;
            }
        }
        if (loaded > 0) console.log(`[Disk Snapshot] Pre-warmed ${loaded} domains`);
    } catch { }
}

export async function getDomainConfig(hostname) {
    const cached = cache.get(hostname);

    // Serve from RAM if fresh and not past absolute staleness bound
    if (cached && cached.expiresAt > Date.now() && !isStale(cached)) {
        metrics.ramHits++;
        metrics.lastRevisionServed = cached.revision;
        return cached.config;
    }

    // Track bounded staleness bounces
    if (cached && cached.expiresAt > Date.now() && isStale(cached)) {
        metrics.staleBounces++;
    }

    // Single-flight: if a fetch for this host is already in progress,
    // reuse the same promise instead of starting a second fetch.
    if (inFlight.has(hostname)) {
        metrics.coalescedRequests++;
        return inFlight.get(hostname);
    }

    const promise = fetchAndCacheConfig(hostname, cached);
    inFlight.set(hostname, promise);
    // Track peak in-flight for stampede visibility
    if (inFlight.size > metrics.peakInFlight) {
        metrics.peakInFlight = inFlight.size;
    }
    try {
        return await promise;
    } finally {
        inFlight.delete(hostname);
    }
}

/**
 * Internal: fetch config from Redis or Laravel, with full fallback chain.
 * Called only once per host per cache-miss cycle (single-flight).
 *
 * @param {string} hostname
 * @param {object|undefined} previousCached - expired/stale RAM entry, if any
 * @returns {Promise<object|null>}
 */
async function fetchAndCacheConfig(hostname, previousCached) {
    // TIER 2: Check Redis (push-cached by Laravel Observer on update)
    try {
        const cachedStr = await redis.get(`proxy_cfg:${hostname}`);
        if (cachedStr) {
            const config = JSON.parse(cachedStr);

            // Revision-based validation: skip Redis if it has an older version
            // than what RAM previously saw. This catches stale Redis after a
            // failed push or partial update.
            if (previousCached?.revision && config.revision) {
                const redisVersion = parseRevisionVersion(config.revision);
                const knownVersion = parseRevisionVersion(
                    previousCached.revision,
                );
                if (redisVersion < knownVersion) {
                    metrics.redisStaleSkips++;
                    // Fall through to HTTP — Redis is stale
                } else {
                    // Redis revision is >= RAM's last known — trust it.
                    // fetchedAt = Date.now(): this is a fresh observation from Redis,
                    // so it gets its own MAX_STALE_MS window from this moment.
                    cacheSet(hostname, {
                        config,
                        expiresAt: Date.now() + jitteredTTL(CACHE_TTL),
                        revision: config.revision,
                        fetchedAt: Date.now(),
                        source: "redis",
                    });
                    metrics.redisHits++;
                    metrics.lastRevisionServed = config.revision;
                    return config;
                }
            } else {
                // COLD BOOT / NO PREVIOUS RAM STATE:
                // After a proxy restart, RAM is empty and we have no revision to compare.
                // This is an explicit availability-leaning decision: accept Redis if present,
                // relying on MAX_STALE_MS (10min) to force authoritative revalidation.
                // The tradeoff is that stale Redis can win for up to MAX_STALE_MS after restart.
                // This is acceptable because:
                //   - Proxy restarts are infrequent (only on routing changes)
                //   - Serving slightly stale config is better than failing closed on cold boot
                //   - MAX_STALE_MS guarantees eventual convergence to MySQL truth
                cacheSet(hostname, {
                    config,
                    expiresAt: Date.now() + jitteredTTL(CACHE_TTL),
                    revision: config.revision,
                    fetchedAt: Date.now(),
                    source: "redis",
                });
                metrics.redisHits++;
                metrics.lastRevisionServed = config.revision;
                return config;
            }
        }
    } catch (redisErr) {
        // Redis unavailable — fall through to HTTP
    }

    // TIER 3: Authoritative fetch from Laravel (MySQL-backed)
    metrics.httpFetches++;
    const laravelUrl = process.env.LARAVEL_URL || "http://127.0.0.1:8000";
    const url = `${laravelUrl}/api/proxy-config/${encodeURIComponent(hostname)}`;

    const headers = {};
    if (LARAVEL_API_HOST) {
        headers["host"] = LARAVEL_API_HOST;
    }
    if (SHARED_SECRET) {
        const sig = signRequest(hostname);
        if (sig) headers["x-proxy-signature"] = sig;
    }
    // Conditional fetch: send ETag if we have a previous revision
    if (previousCached?.revision) {
        headers["if-none-match"] = `"${previousCached.revision}"`;
    }

    let response;
    try {
        response = await undiciRequest(url, {
            method: "GET",
            headers,
            headersTimeout: 5000,
            bodyTimeout: 5000,
        });
    } catch (err) {
        // Network error — fall back to grace mode
        return await fallbackToRedis(hostname, err);
    }

    // 304 Not Modified — config hasn't changed, extend cache TTL
    if (response.statusCode === 304) {
        if (previousCached) {
            previousCached.expiresAt = Date.now() + jitteredTTL(CACHE_TTL);
            previousCached.fetchedAt = Date.now(); // reset authoritative timestamp
            previousCached.source = "http"; // promoted to authoritative
        }
        metrics.notModified304++;
        metrics.lastRevisionServed = previousCached?.revision;
        return previousCached?.config ?? null;
    }

    // 404 — domain not proxy-enabled
    if (response.statusCode === 404) {
        cacheSet(hostname, {
            config: null,
            expiresAt: Date.now() + jitteredTTL(30_000), // 30s jittered negative cache
            revision: null,
            fetchedAt: Date.now(),
            source: "http",
        });
        await response.body.text();
        return null;
    }

    if (response.statusCode !== 200) {
        const bodyText = await response.body.text();
        const error = new Error(
            `Laravel returned ${response.statusCode}: ${bodyText.slice(0, 200)}`,
        );
        return await fallbackToRedis(hostname, error);
    }

    const bodyText = await response.body.text();

    if (SHARED_SECRET) {
        verifySignature(hostname, response.headers["x-signature"], bodyText);
    }

    const config = JSON.parse(bodyText);

    // Cache in RAM — authoritative source, reset staleness timer
    cacheSet(hostname, {
        config,
        expiresAt: Date.now() + jitteredTTL(CACHE_TTL),
        revision: config.revision,
        fetchedAt: Date.now(),
        source: "http",
    });

    // Persist to Redis (grace mode) — 1h TTL
    try {
        await redis.set(`proxy_cfg:${hostname}`, bodyText, "EX", 3600);
    } catch (redisErr) {
        // Non-fatal — don't fail the request for a cache write error
    }

    // Persist to disk (disaster recovery) — survives container restarts
    saveSnapshot(hostname, bodyText);

    metrics.lastFetchAt = new Date().toISOString();
    metrics.lastRevisionServed = config.revision;

    return config;
}

/**
 * Invalidate all derived state for a hostname.
 * Called by Redis pub-sub handler on domain config changes.
 *
 * Invalidates derived layers to prevent stale resurrection:
 *   - RAM cache (always)
 *   - Disk snapshot (always — on pushed, Redis remains authoritative
 *     until the next HTTP fetch recreates the snapshot)
 *   - Redis proxy_cfg:{host} (only on full invalidation, not on pushed
 *     where Redis already has fresh data)
 *
 * @param {string} hostname
 * @param {string} action - 'invalidated' (delete all) or 'pushed' (keep Redis)
 */
export function invalidateConfig(hostname, action = "invalidated") {
    cache.delete(hostname);

    // Always delete the disk snapshot, even on 'pushed' updates.
    // On push, Redis has fresh data — but the old disk snapshot would
    // become a resurrection risk if Redis + Laravel both fail before
    // the next successful HTTP fetch refreshes the snapshot.
    deleteSnapshot(hostname);

    if (action !== "pushed") {
        // Full invalidation: also clear Redis.
        redis.del(`proxy_cfg:${hostname}`).catch(() => {});
    }

    metrics.invalidations++;
    metrics.lastInvalidationAt = new Date().toISOString();
}

/**
 * Get cache stats for health endpoint.
 * Reports per-tier hits, reconciliation counters, and staleness info.
 */
export function getCacheStats() {
    const now = Date.now();
    let active = 0;
    let expired = 0;
    let stale = 0;

    for (const [, entry] of cache) {
        if (entry.expiresAt <= now) expired++;
        else if (isStale(entry)) stale++;
        else active++;
    }

    return {
        // Cache state
        total: cache.size,
        active,
        expired,
        stale,
        inFlightHosts: inFlight.size,
        // Per-tier hits
        ramHits: metrics.ramHits,
        redisHits: metrics.redisHits,
        httpFetches: metrics.httpFetches,
        // Error and recovery
        fetchErrors: metrics.fetchErrors,
        fallbackHits: metrics.fallbackHits,
        // Reconciliation
        coalescedRequests: metrics.coalescedRequests,
        redisStaleSkips: metrics.redisStaleSkips,
        staleBounces: metrics.staleBounces,
        notModified304: metrics.notModified304,
        invalidations: metrics.invalidations,
        peakInFlight: metrics.peakInFlight,
        // Timestamps
        lastFetchAt: metrics.lastFetchAt,
        lastInvalidationAt: metrics.lastInvalidationAt,
        lastRevisionServed: metrics.lastRevisionServed,
    };
}

/**
 * Send a batch of edge metrics to Laravel's ingestion endpoint.
 * HMAC-signed with the same shared secret used for config fetches.
 * Includes batch-level idempotency key for dedup on retries.
 * Fire-and-forget from the caller's perspective — throws on failure.
 *
 * @param {Array<object>} batch - Array of metric events
 * @param {string} batchId - UUID for server-side idempotency
 */
export async function postMetricsBatch(batch, batchId) {
    const body = JSON.stringify(batch);
    const headers = {
        "content-type": "application/json",
    };

    if (batchId) {
        headers["x-batch-id"] = batchId;
    }

    if (LARAVEL_API_HOST) {
        headers["host"] = LARAVEL_API_HOST;
    }

    // Sign payload with shared secret (same as config HMAC)
    if (SHARED_SECRET) {
        const signature = createHmac("sha256", SHARED_SECRET)
            .update(body)
            .digest("hex");
        headers["x-signature"] = signature;
    }

    const response = await undiciRequest(`${LARAVEL_URL}/api/metrics/batch`, {
        method: "POST",
        headers,
        body,
        headersTimeout: 10000, // generous — metrics are async, not blocking proxy
        bodyTimeout: 10000,
    });

    // Drain body to free connection
    const responseText = await response.body.text();

    if (response.statusCode >= 400) {
        throw new Error(
            `Laravel metrics ingest returned ${response.statusCode}: ${responseText.slice(0, 200)}`,
        );
    }
}

/**
 * Send a batch of proxy errors to Laravel for aggregation.
 * Uses the same HMAC-authenticated channel as config fetches.
 * Fire-and-forget — errors buffer in memory, batch flushes every 60s.
 *
 * @param {Array<object>} errors - Array of error objects
 */
export async function postErrorBatch(errors) {
    if (!errors.length) return;
    const body = JSON.stringify({ errors });
    const headers = { 'content-type': 'application/json' };
    if (LARAVEL_API_HOST) headers['host'] = LARAVEL_API_HOST;
    if (SHARED_SECRET) {
        headers['x-signature'] = createHmac('sha256', SHARED_SECRET)
            .update(body).digest('hex');
    }

    const response = await undiciRequest(`${LARAVEL_URL}/api/proxy-errors`, {
        method: 'POST',
        headers,
        body,
        headersTimeout: 5000,
        bodyTimeout: 5000,
    });
    // Drain body to free connection
    await response.body.text();

    if (response.statusCode >= 400) {
        // Silently fail for errors — don't crash the proxy if Laravel is having issues
        console.error(`[Error Bridge] Laravel returned ${response.statusCode} for error batch.`);
    }
}
