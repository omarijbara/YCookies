<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Domain;
use App\Models\RuntimeRevision;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\Publisher\RevisionPublisher;
use App\Services\CrashReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * CompileAndPublishRevision — Queue job that compiles and publishes
 * a new manifest revision for a domain.
 *
 * Dispatched by model observers when consent-relevant configuration changes.
 * Uses the debounce key to prevent compile storms from rapid edits.
 *
 * Failure handling:
 *   - If compilation fails, existing active revision remains untouched
 *   - If publish transaction fails, no partial state exists
 *   - Job is retried up to 3 times with exponential backoff
 */
class CompileAndPublishRevision implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int  $domainId,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue(config('runtime.compile_queue', 'default'));
    }

    public function handle(DomainCompiler $compiler, RevisionPublisher $publisher): void
    {
        // 1. Load domain without tenant scope (this runs from queue, not from auth context)
        $domain = Domain::withoutGlobalScope('tenant')->find($this->domainId);

        if (!$domain) {
            Log::warning("CompileAndPublishRevision: Domain {$this->domainId} not found — skipping");
            return;
        }

        // 2. Skip if manifest compilation is not enabled for this domain
        if (!$domain->manifest_enabled) {
            return;
        }

        // 3. Compile
        try {
            $result = $compiler->compile($domain);
        } catch (\Throwable $e) {
            Log::error("CompileAndPublishRevision: Compilation failed for {$domain->name}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            CrashReporter::report($e, [
                'source' => 'manifest-compiler',
                'url'    => '/admin/installation',
                'domain' => $domain->name,
            ]);
            throw $e; // Let the queue retry
        }

        // 4. Check if inputs actually changed (skip no-op publishes)
        $lastRevision = RuntimeRevision::where('domain_id', $domain->id)
            ->where('status', 'published')
            ->orderByDesc('revision_number')
            ->first();

        if ($lastRevision && $lastRevision->compile_inputs_hash === $result->compileInputsHash) {
            Log::debug("CompileAndPublishRevision: No changes for {$domain->name} — skipping publish");
            return;
        }

        // 5. Publish
        try {
            $revision = $publisher->publish($domain, $result, $this->userId);
        } catch (\Throwable $e) {
            Log::error("CompileAndPublishRevision: Publish failed for {$domain->name}", [
                'error' => $e->getMessage(),
            ]);
            CrashReporter::report($e, [
                'source' => 'manifest-publisher',
                'url'    => '/admin/installation',
                'domain' => $domain->name,
            ]);
            throw $e;
        }

        // 6. Post-commit acceleration (non-fatal)
        $publisher->postPublishAccelerate($domain, $revision);

        // 7. Prune old revisions
        $this->pruneOldRevisions($domain->id);

        Log::info("CompileAndPublishRevision: Published revision {$revision->revision_number} for {$domain->name}");
    }

    /**
     * Prune old revisions beyond the retention limit.
     */
    protected function pruneOldRevisions(int $domainId): void
    {
        $maxRevisions = config('runtime.max_revisions_per_domain', 20);

        $revisionIds = RuntimeRevision::where('domain_id', $domainId)
            ->orderByDesc('revision_number')
            ->skip($maxRevisions)
            ->take(1000)
            ->pluck('id');

        if ($revisionIds->isNotEmpty()) {
            // Don't delete active revision
            $activeRevisionId = Domain::withoutGlobalScope('tenant')
                ->where('id', $domainId)
                ->value('active_revision_id');

            RuntimeRevision::whereIn('id', $revisionIds)
                ->where('id', '!=', $activeRevisionId)
                ->delete();
        }
    }

    /**
     * Generate a unique job ID for debouncing (same domain → same job).
     */
    public function uniqueId(): string
    {
        return "compile_publish_{$this->domainId}";
    }

    /**
     * Debounce window — within this time, duplicate dispatches are deduplicated.
     */
    public function uniqueFor(): int
    {
        return config('runtime.compile_debounce_seconds', 5);
    }
}
