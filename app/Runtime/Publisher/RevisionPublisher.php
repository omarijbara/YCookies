<?php

declare(strict_types=1);

namespace App\Runtime\Publisher;

use App\Models\Domain;
use App\Models\RuntimeRevision;
use App\Runtime\Compiler\CompileResult;
use App\Runtime\Consumer\RevisionResolver;
use App\Runtime\ManifestMetrics;
use App\Runtime\Schema\ManifestSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * RevisionPublisher — Atomically publishes compiled artifacts as a new revision.
 *
 * Transaction contract:
 *   1. Acquire next revision number (monotonic per domain)
 *   2. Create RuntimeRevision record (status=draft)
 *   3. Write artifact columns (signed)
 *   4. Update revision status → published
 *   5. Move domain.active_revision_id pointer
 *   6. COMMIT — all or nothing
 *
 * Post-commit (best-effort, non-fatal):
 *   7. Mirror to Redis for proxy cache acceleration
 *   8. Emit invalidation event via Redis pub/sub
 *   9. Clear legacy cache keys
 */
class RevisionPublisher
{
    public function __construct(
        private readonly RevisionSigner $signer,
    ) {}

    /**
     * Publish a new revision for a domain.
     *
     * @param Domain        $domain  The domain to publish for
     * @param CompileResult $result  The compilation output
     * @param int|null      $userId  Who triggered the compile (null = system)
     * @return RuntimeRevision The created and published revision
     *
     * @throws \Throwable On transaction failure
     */
    public function publish(Domain $domain, CompileResult $result, ?int $userId = null): RuntimeRevision
    {
        return DB::transaction(function () use ($domain, $result, $userId) {
            // 1. Acquire next revision number
            $nextRevision = $this->nextRevisionNumber($domain->id);

            // 2. Update manifest with real revision number and sign it
            $manifest = $result->manifest;
            $manifest['revision'] = $nextRevision;
            $manifest['issued_at'] = now()->toIso8601String();

            // 3. Sign the manifest
            $signature = $this->signer->sign($manifest);
            $manifest['signature'] = $signature;

            // Re-canonicalize with real revision + signature
            $manifestJson = ManifestSchema::canonicalize($manifest);
            $manifestHash = hash('sha256', $manifestJson);

            // 4. Create revision record
            $revision = RuntimeRevision::create([
                'domain_id'          => $domain->id,
                'revision_number'    => $nextRevision,
                'schema_version'     => ManifestSchema::SCHEMA_VERSION,
                'status'             => 'published',
                'manifest_json'      => $manifestJson,
                'manifest_hash'      => $manifestHash,
                'manifest_signature' => $signature,
                'base_artifact_json' => $result->baseArtifactJson,
                'base_artifact_hash' => $result->baseArtifactHash,
                'route_index_json'   => $result->routeIndexJson,
                'route_index_hash'   => $result->routeIndexHash,
                'compiled_by'        => $userId,
                'compile_inputs_hash' => $result->compileInputsHash,
                'published_at'       => now(),
            ]);

            // 5. Create overlay records
            foreach ($result->overlays as $overlay) {
                $revision->overlays()->create([
                    'overlay_id'    => $overlay['overlay_id'],
                    'route_pattern' => $overlay['route_pattern'],
                    'overlay_json'  => $overlay['overlay_json'],
                    'overlay_hash'  => $overlay['overlay_hash'],
                ]);
            }

            // 6. Move domain pointer (within same transaction)
            // Using raw DB update to avoid triggering DomainObserver recursion
            DB::table('domains')
                ->where('id', $domain->id)
                ->update(['active_revision_id' => $revision->id]);

            Log::info('Runtime revision published', [
                'domain'   => $domain->name,
                'revision' => $nextRevision,
                'hash'     => $manifestHash,
            ]);

            return $revision;
        });
    }

    /**
     * Post-commit cache acceleration.
     *
     * Called AFTER the transaction commits. Failures are non-fatal
     * and should never prevent publication.
     */
    public function postPublishAccelerate(Domain $domain, RuntimeRevision $revision): void
    {
        // 0. Invalidate resolver cache immediately (most critical)
        try {
            app(RevisionResolver::class)->invalidate($domain->name);
        } catch (\Throwable $e) {
            Log::warning("Failed to invalidate resolver cache for {$domain->name}: {$e->getMessage()}");
        }

        // 1. Mirror manifest to Redis for proxy cache acceleration
        try {
            $redisKey = "manifest:{$domain->name}";
            Redis::connection('proxy')->setex(
                $redisKey,
                3600, // 1 hour TTL
                $revision->manifest_json,
            );
        } catch (\Throwable $e) {
            Log::warning("Failed to cache manifest in Redis for {$domain->name}: {$e->getMessage()}");
            ManifestMetrics::increment('redis_mirror_failures');
        }

        // 2. Emit invalidation event via Redis pub/sub
        try {
            $payload = json_encode([
                'host'     => $domain->name,
                'version'  => $revision->revision_number,
                'action'   => 'manifest_published',
                'revision' => $revision->revision_number,
            ]);
            Redis::connection('pubsub')->publish('domain-config-updated', $payload);
        } catch (\Throwable $e) {
            Log::warning("Failed to publish manifest event for {$domain->name}: {$e->getMessage()}");
            ManifestMetrics::increment('redis_pubsub_failures');
        }

        // 3. Clear legacy cache keys
        try {
            Cache::forget("proxy_config:{$domain->name}");
            Cache::forget("consent_config:{$domain->site_id}");
        } catch (\Throwable $e) {
            Log::warning("Failed to clear legacy caches for {$domain->name}: {$e->getMessage()}");
        }

        // 4. Warm proxy cache — issue local GETs so Cache::remember() is populated
        $this->warmEndpointCaches($domain, $revision);
    }

    /**
     * Warm the SDK endpoint caches by issuing local HTTP GETs.
     *
     * This populates the Cache::remember() in ManifestProjectionController,
     * ScriptDeliveryController, and BootstrapperController so the first real
     * user request is served from warm cache (target: <200ms).
     *
     * Failures are non-fatal — the endpoints will still work, just slower
     * on the first request.
     */
    protected function warmEndpointCaches(Domain $domain, RuntimeRevision $revision): void
    {
        if (!$domain->site_id) {
            Log::debug("Skipping proxy warm for {$domain->name}: no site_id");
            return;
        }

        $baseUrl = rtrim(config('app.url'), '/');
        $endpoints = [
            'config' => "{$baseUrl}/api/config/{$domain->site_id}",
            'script' => "{$baseUrl}/api/script/{$domain->site_id}.js",
            'boot'   => "{$baseUrl}/api/boot/{$domain->site_id}.js",
        ];

        $start = microtime(true);
        $failures = [];

        foreach ($endpoints as $name => $url) {
            try {
                $response = Http::timeout(3)
                    ->retry(2, 100)
                    ->get($url);

                if ($response->status() !== 200) {
                    $failures[] = "{$name}=HTTP{$response->status()}";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$name}={$e->getMessage()}";
            }
        }

        $elapsedMs = (int) ((microtime(true) - $start) * 1000);

        if (empty($failures)) {
            Log::info("proxy-warm complete for {$domain->name} rev={$revision->revision_number} time={$elapsedMs}ms");
        } else {
            Log::warning("proxy-warm partial for {$domain->name} rev={$revision->revision_number} time={$elapsedMs}ms failures=" . implode(',', $failures));
            ManifestMetrics::increment('proxy_warm_failures');
        }
    }

    /**
     * Rollback to a previous revision.
     *
     * @param Domain $domain          The domain to rollback
     * @param int    $targetRevision  The revision number to rollback to
     * @return RuntimeRevision The revision that is now active
     *
     * @throws \InvalidArgumentException If target revision doesn't exist or isn't published
     */
    public function rollback(Domain $domain, int $targetRevision): RuntimeRevision
    {
        $revision = DB::transaction(function () use ($domain, $targetRevision) {
            // Refresh to get the DB-current active_revision_id
            // (publish() uses raw DB::table() update, so the in-memory model may be stale)
            $domain->refresh();

            $target = RuntimeRevision::where('domain_id', $domain->id)
                ->where('revision_number', $targetRevision)
                ->where('status', 'published')
                ->firstOrFail();

            // Mark current active revision as rolled back
            if ($domain->active_revision_id) {
                RuntimeRevision::where('id', $domain->active_revision_id)
                    ->update([
                        'status'         => 'rolled_back',
                        'rolled_back_at' => now(),
                    ]);
            }

            // Move pointer
            DB::table('domains')
                ->where('id', $domain->id)
                ->update(['active_revision_id' => $target->id]);

            Log::info('Runtime revision rolled back', [
                'domain'          => $domain->name,
                'target_revision' => $targetRevision,
            ]);

            return $target->fresh();
        });

        // Invalidate resolver cache after rollback commit
        try {
            app(RevisionResolver::class)->invalidate($domain->name);
        } catch (\Throwable $e) {
            Log::warning("Failed to invalidate resolver cache after rollback for {$domain->name}: {$e->getMessage()}");
        }

        return $revision;
    }

    /**
     * Get the next monotonic revision number for a domain.
     *
     * Uses SELECT ... FOR UPDATE on the domain row to serialize concurrent
     * allocations within the enclosing DB::transaction(). This prevents
     * two concurrent publish() calls from reading the same MAX() and
     * colliding on the unique(domain_id, revision_number) constraint.
     *
     * The lock is held for the duration of the transaction (typically < 100ms).
     */
    protected function nextRevisionNumber(int $domainId): int
    {
        // Acquire exclusive row lock on the domain to serialize allocation.
        // This runs inside the existing DB::transaction() in publish().
        DB::table('domains')->where('id', $domainId)->lockForUpdate()->first();

        $max = RuntimeRevision::where('domain_id', $domainId)->max('revision_number');
        return ($max ?? 0) + 1;
    }
}
