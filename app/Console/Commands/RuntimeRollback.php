<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\RuntimeRevision;
use App\Runtime\Publisher\RevisionPublisher;
use Illuminate\Console\Command;

/**
 * Artisan command: rollback a domain to a previous revision.
 *
 * Usage:
 *   php artisan runtime:rollback duftz.de                # Rollback to previous revision
 *   php artisan runtime:rollback duftz.de --revision=5    # Rollback to specific revision
 */
class RuntimeRollback extends Command
{
    protected $signature = 'runtime:rollback
                            {domain : Domain hostname to rollback}
                            {--revision= : Specific revision number to rollback to}';

    protected $description = 'Rollback a domain to a previous runtime manifest revision';

    public function handle(RevisionPublisher $publisher): int
    {
        $domainName = $this->argument('domain');

        $domain = Domain::withoutGlobalScope('tenant')
            ->where('name', $domainName)
            ->first();

        if (!$domain) {
            $this->error("Domain '{$domainName}' not found");
            return self::FAILURE;
        }

        if (!$domain->active_revision_id) {
            $this->error("Domain '{$domainName}' has no active revision");
            return self::FAILURE;
        }

        // Determine target revision
        $targetRevision = $this->option('revision');

        if ($targetRevision) {
            $targetRevision = (int) $targetRevision;
        } else {
            // Find the previous published revision
            $currentRevision = RuntimeRevision::find($domain->active_revision_id);
            if (!$currentRevision) {
                $this->error('Current active revision not found');
                return self::FAILURE;
            }

            $previous = RuntimeRevision::where('domain_id', $domain->id)
                ->where('status', 'published')
                ->where('revision_number', '<', $currentRevision->revision_number)
                ->orderByDesc('revision_number')
                ->first();

            if (!$previous) {
                $this->error("No previous published revision found for {$domainName}");
                return self::FAILURE;
            }

            $targetRevision = $previous->revision_number;
        }

        // Confirm rollback
        if (!$this->confirm("Rollback {$domainName} to revision {$targetRevision}?")) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        try {
            $revision = $publisher->rollback($domain, $targetRevision);
            $publisher->postPublishAccelerate($domain->fresh(), $revision);

            $this->info("✓ Rolled back {$domainName} to revision {$targetRevision}");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Active Revision', $revision->revision_number],
                    ['Published At', $revision->published_at?->toDateTimeString()],
                    ['Manifest Hash', substr($revision->manifest_hash, 0, 16) . '...'],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Rollback failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
