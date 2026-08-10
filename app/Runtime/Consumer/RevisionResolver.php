<?php

declare(strict_types=1);

namespace App\Runtime\Consumer;

use App\Models\Domain;
use App\Models\RuntimeRevision;
use App\Runtime\ManifestMetrics;
use App\Runtime\Publisher\RevisionSigner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RevisionResolver — Retrieves, verifies, and decodes published revisions.
 *
 * Used by:
 *   - ProxyConfigController (compatibility projection)
 *   - ConsentConfigController (compatibility projection)
 *   - ManifestController (direct manifest delivery)
 *   - Artisan commands (inspection)
 *
 * Cache tiers:
 *   1. Laravel Cache (5 min TTL)
 *   2. Database lookup + signature verification
 *
 * Fail-closed: returns null if domain has no active revision OR
 * if signature verification fails.
 */
class RevisionResolver
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Sentinel value stored in cache when verification fails.
     * Prevents re-querying the DB on every request during the cache window.
     */
    private const VERIFICATION_FAILED_SENTINEL = '__verification_failed__';

    private RevisionSigner $signer;

    public function __construct(RevisionSigner $signer)
    {
        $this->signer = $signer;
    }

    /**
     * Resolve the active revision for a domain by name.
     *
     * @param string $domainName  The domain hostname (e.g., 'duftz.de')
     * @return ResolvedRevision|null  Null if domain not found, no active revision,
     *                                or signature verification fails (fail-closed)
     */
    public function resolveActive(string $domainName): ?ResolvedRevision
    {
        $cacheKey = "manifest_resolved:{$domainName}";

        $cached = Cache::get($cacheKey);

        // Sentinel check: verification previously failed, don't re-query
        if ($cached === self::VERIFICATION_FAILED_SENTINEL) {
            ManifestMetrics::increment('resolver_cache_hits');
            return null;
        }

        if ($cached instanceof ResolvedRevision) {
            ManifestMetrics::increment('resolver_cache_hits');
            return $cached;
        }

        ManifestMetrics::increment('resolver_cache_misses');

        // Cache miss — resolve from DB (may set sentinel internally)
        $resolved = $this->resolveFromDatabase($domainName);

        if ($resolved === null) {
            // Only cache null if resolveFromDatabase didn't already set a sentinel
            if (Cache::get($cacheKey) !== self::VERIFICATION_FAILED_SENTINEL) {
                Cache::put($cacheKey, null, self::CACHE_TTL);
            }
            return null;
        }

        // Cache the valid result (decrement sentinel_active if replacing a sentinel)
        if (Cache::get($cacheKey) === self::VERIFICATION_FAILED_SENTINEL) {
            ManifestMetrics::decrement('sentinel_active');
        }
        Cache::put($cacheKey, $resolved, self::CACHE_TTL);
        return $resolved;
    }

    /**
     * Resolve a specific revision by domain ID and revision number.
     *
     * @param int $domainId        The domain ID
     * @param int $revisionNumber  The specific revision number
     * @return ResolvedRevision|null
     */
    public function resolveRevision(int $domainId, int $revisionNumber): ?ResolvedRevision
    {
        $revision = RuntimeRevision::with('overlays')
            ->where('domain_id', $domainId)
            ->where('revision_number', $revisionNumber)
            ->where('status', 'published')
            ->first();

        if (!$revision) {
            return null;
        }

        $domainName = Domain::withoutGlobalScope('tenant')
            ->where('id', $domainId)
            ->value('name') ?? 'unknown';

        return $this->buildResolvedRevision($revision, $domainName);
    }

    /**
     * Invalidate the cached resolved revision for a domain.
     */
    public function invalidate(string $domainName): void
    {
        $cacheKey = "manifest_resolved:{$domainName}";
        $wasSentinel = Cache::get($cacheKey) === self::VERIFICATION_FAILED_SENTINEL;
        Cache::forget($cacheKey);
        ManifestMetrics::increment('invalidations');
        if ($wasSentinel) {
            ManifestMetrics::decrement('sentinel_active');
        }
    }

    /**
     * Resolve from database with signature verification.
     */
    protected function resolveFromDatabase(string $domainName): ?ResolvedRevision
    {
        $domain = Domain::withoutGlobalScope('tenant')
            ->where('name', $domainName)
            ->where('manifest_enabled', true)
            ->whereNotNull('active_revision_id')
            ->first();

        if (!$domain || !$domain->active_revision_id) {
            return null;
        }

        $revision = RuntimeRevision::with('overlays')
            ->where('id', $domain->active_revision_id)
            ->where('status', 'published')
            ->first();

        if (!$revision) {
            Log::warning("RevisionResolver: Active revision {$domain->active_revision_id} for {$domainName} not found or not published");
            return null;
        }

        // ── Signature verification (fail-closed) ──────────────
        if (config('runtime.verify_on_read', true)) {
            $manifest = $revision->getManifest();
            $signature = $manifest['signature'] ?? null;

            if (!$signature) {
                Log::error('RevisionResolver: signature missing from manifest', [
                    'domain'   => $domainName,
                    'revision' => $revision->revision_number,
                ]);
                ManifestMetrics::increment('verification_missing_signature');
                ManifestMetrics::increment('verification_failures');
                // Cache sentinel to avoid re-querying
                Cache::put("manifest_resolved:{$domainName}", self::VERIFICATION_FAILED_SENTINEL, self::CACHE_TTL);
                ManifestMetrics::increment('sentinel_active');
                return null;
            }

            if (!$this->signer->verify($manifest, $signature)) {
                Log::error('RevisionResolver: signature verification failed', [
                    'domain'   => $domainName,
                    'revision' => $revision->revision_number,
                    'hash'     => $revision->manifest_hash,
                ]);
                ManifestMetrics::increment('verification_failures');
                // Cache sentinel to avoid re-querying
                Cache::put("manifest_resolved:{$domainName}", self::VERIFICATION_FAILED_SENTINEL, self::CACHE_TTL);
                ManifestMetrics::increment('sentinel_active');
                return null;
            }

            ManifestMetrics::increment('verification_successes');
        }

        return $this->buildResolvedRevision($revision, $domainName);
    }

    /**
     * Build a ResolvedRevision from a RuntimeRevision model.
     */
    protected function buildResolvedRevision(RuntimeRevision $revision, string $domainName): ResolvedRevision
    {
        $overlaysMap = [];
        foreach ($revision->overlays as $overlay) {
            $overlaysMap[$overlay->overlay_id] = $overlay->getOverlay();
        }

        return new ResolvedRevision(
            manifest: $revision->getManifest(),
            baseArtifact: $revision->getBaseArtifact(),
            routeIndex: $revision->getRouteIndex(),
            overlays: $overlaysMap,
            revisionNumber: $revision->revision_number,
            schemaVersion: $revision->schema_version,
            manifestHash: $revision->manifest_hash,
            domainName: $domainName,
            manifestSignature: $revision->manifest_signature,
            publishedAt: $revision->published_at?->toIso8601String(),
        );
    }
}
