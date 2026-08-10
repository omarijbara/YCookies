<?php

namespace App\Jobs;

use App\Services\CoolifyService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Batch-sync all active Node-proxy domains to Coolify's application routing.
 *
 * Dispatched by DomainObserver on create/update/delete events.
 * - ShouldBeUnique + uniqueFor(30): prevents duplicate jobs from being QUEUED
 * - WithoutOverlapping: prevents concurrent EXECUTION if a sync runs long
 */
class SyncCoolifyDomains implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    /**
     * Execution-time lock: if a sync is already running, wait up to 60s
     * before giving up. This prevents two syncs from patching Coolify
     * simultaneously and creating a race condition.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping())->expireAfter(60),
        ];
    }

    /**
     * Only one instance of this job can exist in the queue within this window.
     * If a domain is created, then another edited 5s later, only one sync fires.
     */
    public int $uniqueFor = 30;

    /**
     * Retry up to 3 times with a 10-second backoff between attempts.
     */
    public int $tries = 3;
    public int $backoff = 10;

    public function handle(CoolifyService $coolify): void
    {
        $result = $coolify->syncDomains();

        if (isset($result['changed']) && $result['changed']) {
            Log::info('[SyncCoolifyDomains] Domain routing updated in Coolify', [
                'domain_count' => count($result['domains'] ?? []),
            ]);
        } else {
            Log::info('[SyncCoolifyDomains] No changes detected, skipping Coolify update', [
                'message' => $result['message'] ?? 'No message',
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncCoolifyDomains] Failed to sync domains to Coolify', [
            'error' => $exception->getMessage(),
        ]);
        \App\Services\CrashReporter::report($exception, [
            'source' => 'coolify-sync',
            'url'    => '/admin/settings',
        ]);
    }
}
