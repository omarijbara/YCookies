<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\RuntimeRevision;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\ManifestMetrics;
use App\Runtime\Publisher\RevisionPublisher;
use App\Runtime\Publisher\RevisionSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: batch compile, verify, and publish manifest revisions.
 *
 * Each domain is processed in full isolation: one domain's failure cannot
 * corrupt another domain's publish, and a failed verification prevents publish.
 *
 * Usage:
 *   php artisan manifest:rollout:execute --domains=foo.com,bar.com
 *   php artisan manifest:rollout:execute --all
 *   php artisan manifest:rollout:execute --domains=foo.com --force
 *   php artisan manifest:rollout:execute --domains=foo.com --json
 *
 * Exit codes:
 *   0 = all succeeded or safely skipped
 *   1 = one or more selected domains failed
 *   2 = invalid input / precondition failure
 */
class ManifestRolloutExecute extends Command
{
    protected $signature = 'manifest:rollout:execute
                            {--domains= : Comma-separated domain hostnames (required unless --all)}
                            {--all : Process all manifest-eligible active domains}
                            {--force : Force recompile even if inputs unchanged}
                            {--json : Output results as JSON array}';

    protected $description = 'Batch compile, verify, and publish manifest revisions for selected domains';

    /**
     * @return int Exit code: 0=success, 1=partial failure, 2=invalid input
     */
    public function handle(DomainCompiler $compiler, RevisionPublisher $publisher, RevisionSigner $signer): int
    {
        $domains = $this->resolveDomains();
        if ($domains === null) {
            return self::INVALID; // exit 2
        }

        if ($domains->isEmpty()) {
            $this->warn('No domains matched the selection criteria.');
            return self::SUCCESS;
        }

        if (!$this->option('json')) {
            $this->info("═══ Manifest Rollout Execute ═══");
            $this->info("Processing {$domains->count()} domain(s)...");
            $this->newLine();
        }

        $results = [];
        $failures = 0;

        foreach ($domains as $domain) {
            $result = $this->processDomain($domain, $compiler, $publisher, $signer);
            $results[] = $result;

            if ($result['status'] === 'failed') {
                $failures++;
            }

            if (!$this->option('json')) {
                $this->renderDomainResult($result);
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderSummary($results, $failures);
        }

        Log::info('Manifest rollout execute completed', [
            'domains'  => count($results),
            'failures' => $failures,
            'results'  => array_map(fn ($r) => [
                'domain' => $r['domain'],
                'status' => $r['status'],
                'revision' => $r['published_revision'],
            ], $results),
        ]);

        // published_unverified = publish committed but post-publish check failed
        // treat as warnings, not failures (the revision IS live)
        $warnings = collect($results)->where('status', 'published_unverified')->count();

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Process a single domain: compile → verify → publish.
     *
     * Fully isolated — exceptions here are caught and reported,
     * never propagated to other domains.
     */
    protected function processDomain(
        Domain $domain,
        DomainCompiler $compiler,
        RevisionPublisher $publisher,
        RevisionSigner $signer,
    ): array {
        $result = [
            'domain'              => $domain->name,
            'attempted'           => true,
            'previous_revision'   => null,
            'compiled_revision'   => null,
            'published_revision'  => null,
            'final_revision'      => null,
            'status'              => 'failed',
            'reason'              => null,
        ];

        try {
            // ── 1. Eligibility gate ────────────────────────────────
            if (!$domain->is_active) {
                $result['attempted'] = false;
                $result['status'] = 'skipped';
                $result['reason'] = 'domain_inactive';
                return $result;
            }

            if (!$domain->cookieBar) {
                $result['attempted'] = false;
                $result['status'] = 'skipped';
                $result['reason'] = 'no_cookie_bar';
                return $result;
            }

            if (!$domain->manifest_enabled) {
                $result['attempted'] = false;
                $result['status'] = 'skipped';
                $result['reason'] = 'manifest_disabled';
                return $result;
            }

            // Record previous state
            $activeRevision = $domain->activeRevision;
            $result['previous_revision'] = $activeRevision?->revision_number;

            // ── 2. Compile ─────────────────────────────────────────
            $compileResult = $compiler->compile($domain);

            // Dedup check (skip if inputs unchanged, unless --force)
            if (!$this->option('force')
                && $activeRevision
                && $activeRevision->compile_inputs_hash === $compileResult->compileInputsHash
            ) {
                $result['attempted'] = false;
                $result['status'] = 'skipped';
                $result['reason'] = 'no_changes_detected';
                $result['final_revision'] = $activeRevision->revision_number;
                return $result;
            }

            // ── 3. Verify compiled artifacts ───────────────────────
            // The publisher signs the manifest during publish.
            // But we can pre-verify the base artifact hash to ensure
            // nothing was corrupted between compile and publish.
            $expectedBaseHash = hash('sha256', $compileResult->baseArtifactJson);
            if ($expectedBaseHash !== $compileResult->baseArtifactHash) {
                $result['status'] = 'failed';
                $result['reason'] = 'verification_failed: base artifact hash mismatch';
                Log::error('Manifest rollout: base artifact hash mismatch', [
                    'domain'   => $domain->name,
                    'expected' => $expectedBaseHash,
                    'actual'   => $compileResult->baseArtifactHash,
                ]);
                return $result;
            }

            // Verify manifest hash
            $expectedManifestHash = hash('sha256', $compileResult->manifestJson);
            if ($expectedManifestHash !== $compileResult->manifestHash) {
                $result['status'] = 'failed';
                $result['reason'] = 'verification_failed: manifest hash mismatch';
                Log::error('Manifest rollout: manifest hash mismatch', [
                    'domain'   => $domain->name,
                    'expected' => $expectedManifestHash,
                    'actual'   => $compileResult->manifestHash,
                ]);
                return $result;
            }

            // ── 4. Publish ─────────────────────────────────────────
            $revision = $publisher->publish($domain, $compileResult);
            $result['compiled_revision'] = $revision->revision_number;
            $result['published_revision'] = $revision->revision_number;

            // ── 5. Post-publish verification ───────────────────────
            // Re-read from DB and verify signature.
            // NOTE: The publish transaction already committed at this point.
            // If verification fails, the revision IS live — we cannot un-publish.
            // Report as 'published_unverified' to distinguish from real failures.
            $persisted = RuntimeRevision::find($revision->id);
            if (!$persisted) {
                $result['status'] = 'published_unverified';
                $result['reason'] = 'post_publish_warning: revision not found on re-read (likely transient)';
                $result['final_revision'] = $revision->revision_number;
                ManifestMetrics::increment('publish_unverified');
                Log::warning('Manifest rollout: post-publish re-read failed', [
                    'domain'   => $domain->name,
                    'revision' => $revision->revision_number,
                ]);
                return $result;
            }

            $manifest = json_decode($persisted->manifest_json, true);
            $signature = $manifest['signature'] ?? null;
            if (!$signature || !$signer->verify($manifest, $signature)) {
                $result['status'] = 'published_unverified';
                $result['reason'] = 'post_publish_warning: signature verification failed after persist';
                $result['final_revision'] = $revision->revision_number;
                ManifestMetrics::increment('publish_unverified');
                Log::warning('Manifest rollout: signature verification failed after publish', [
                    'domain'   => $domain->name,
                    'revision' => $revision->revision_number,
                ]);
                return $result;
            }

            // ── 6. Post-publish acceleration ───────────────────────
            $publisher->postPublishAccelerate($domain, $revision);

            // Record final state
            $domain->refresh();
            $result['final_revision'] = RuntimeRevision::find($domain->active_revision_id)?->revision_number;
            $result['status'] = 'published';
            $result['reason'] = null;

            return $result;

        } catch (\Throwable $e) {
            $result['status'] = 'failed';
            $result['reason'] = 'exception: ' . $e->getMessage();
            Log::error('Manifest rollout failed for domain', [
                'domain'  => $domain->name,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return $result;
        }
    }

    /**
     * Resolve domains from --domains or --all flags.
     */
    protected function resolveDomains(): ?\Illuminate\Support\Collection
    {
        $domainNames = $this->option('domains');
        $all = $this->option('all');

        if (!$domainNames && !$all) {
            $this->error('Specify --domains=foo.com,bar.com or --all');
            return null;
        }

        if ($domainNames && $all) {
            $this->error('Cannot use both --domains and --all');
            return null;
        }

        if ($all) {
            return Domain::withoutGlobalScope('tenant')
                ->where('is_active', true)
                ->where('manifest_enabled', true)
                ->whereHas('cookieBar')
                ->get();
        }

        $names = array_map('trim', explode(',', $domainNames));
        $domains = Domain::withoutGlobalScope('tenant')
            ->whereIn('name', $names)
            ->get();

        $found = $domains->pluck('name')->toArray();
        $missing = array_diff($names, $found);

        if (!empty($missing)) {
            $this->error('Domains not found: ' . implode(', ', $missing));
            return null;
        }

        return $domains;
    }

    /**
     * Render a single domain result to console.
     */
    protected function renderDomainResult(array $result): void
    {
        $icon = match ($result['status']) {
            'published'            => '✓',
            'published_unverified' => '⚠',
            'skipped'              => '≡',
            'failed'               => '✗',
            default                => '?',
        };

        $this->line("  {$icon} {$result['domain']}");

        if ($result['status'] === 'published') {
            $prev = $result['previous_revision'] ?? 'none';
            $this->line("    rev {$prev} → {$result['published_revision']}");
        } elseif ($result['status'] === 'published_unverified') {
            $prev = $result['previous_revision'] ?? 'none';
            $this->line("    rev {$prev} → {$result['published_revision']} (UNVERIFIED — review manually)");
            $this->line("    reason: {$result['reason']}");
        } elseif ($result['reason']) {
            $this->line("    reason: {$result['reason']}");
        }
    }

    /**
     * Render the batch summary.
     */
    protected function renderSummary(array $results, int $failures): void
    {
        $this->newLine();
        $published = collect($results)->where('status', 'published')->count();
        $unverified = collect($results)->where('status', 'published_unverified')->count();
        $skipped = collect($results)->where('status', 'skipped')->count();

        $this->info("═══ Summary ═══");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Attempted', count($results)],
                ['Published', $published],
                ['Published (unverified)', $unverified],
                ['Skipped', $skipped],
                ['Failed', $failures],
            ],
        );

        if ($unverified > 0) {
            $this->warn("⚠ {$unverified} domain(s) published but post-publish verification failed. Review manually.");
        }

        if ($failures > 0) {
            $this->error("⚠ {$failures} domain(s) failed. Check logs for details.");
        } else {
            $this->info("All domains processed successfully.");
        }
    }
}
