<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\RuntimeRevision;
use App\Runtime\Consumer\RevisionResolver;
use Illuminate\Console\Command;

/**
 * Artisan command: inspect a domain's manifest state.
 *
 * Usage:
 *   php artisan runtime:inspect duftz.de
 */
class RuntimeInspect extends Command
{
    protected $signature = 'runtime:inspect
                            {domain : Domain hostname to inspect}
                            {--history=5 : Number of recent revisions to show}';

    protected $description = 'Inspect a domain\'s runtime manifest state and revision history';

    public function handle(RevisionResolver $resolver): int
    {
        $domainName = $this->argument('domain');
        $historyCount = (int) $this->option('history');

        $domain = Domain::withoutGlobalScope('tenant')
            ->where('name', $domainName)
            ->first();

        if (!$domain) {
            $this->error("Domain '{$domainName}' not found");
            return self::FAILURE;
        }

        // Domain status
        $this->info("═══ Domain: {$domainName} ═══");
        $this->newLine();

        $this->table(['Property', 'Value'], [
            ['Manifest Enabled', $domain->manifest_enabled ? '✓ Yes' : '✗ No'],
            ['Active Revision ID', $domain->active_revision_id ?? 'None'],
            ['Config Version', $domain->config_version],
            ['Consent Version', $domain->consent_version],
            ['Proxy Enabled', $domain->proxy_enabled ? '✓ Yes' : '✗ No'],
            ['Site ID', $domain->site_id],
        ]);

        if (!$domain->manifest_enabled) {
            $this->warn('Manifest is not enabled for this domain. Enable with manifest_enabled=true.');
            return self::SUCCESS;
        }

        // Active revision details
        if ($domain->active_revision_id) {
            $resolved = $resolver->resolveActive($domainName);
            if ($resolved) {
                $this->newLine();
                $this->info('── Active Revision ──');
                $this->table(['Property', 'Value'], [
                    ['Revision #', $resolved->revisionNumber],
                    ['Schema Version', $resolved->schemaVersion],
                    ['Manifest Hash', $resolved->manifestHash],
                    ['Overlays', count($resolved->overlays)],
                    ['Has Route Index', $resolved->routeIndex ? 'Yes' : 'No'],
                    ['Cookie Groups', count($resolved->baseArtifact['cookie_groups'] ?? [])],
                    ['Script Blockers', count($resolved->baseArtifact['script_blockers'] ?? [])],
                    ['Content Blockers', count($resolved->baseArtifact['content_blockers'] ?? [])],
                    ['Proxy Config', isset($resolved->baseArtifact['origin']) ? 'Present' : 'None'],
                ]);
            }
        } else {
            $this->warn('No active revision. Run: php artisan runtime:publish ' . $domainName);
        }

        // Revision history
        $revisions = RuntimeRevision::where('domain_id', $domain->id)
            ->orderByDesc('revision_number')
            ->limit($historyCount)
            ->get();

        if ($revisions->isNotEmpty()) {
            $this->newLine();
            $this->info("── Recent Revisions (last {$historyCount}) ──");
            $rows = $revisions->map(fn ($r) => [
                $r->revision_number,
                $r->status,
                substr($r->manifest_hash, 0, 12) . '...',
                substr($r->compile_inputs_hash, 0, 12) . '...',
                $r->published_at?->diffForHumans() ?? '-',
                $r->id === $domain->active_revision_id ? '→ ACTIVE' : '',
            ]);
            $this->table(
                ['Rev #', 'Status', 'Manifest Hash', 'Inputs Hash', 'Published', 'Pointer'],
                $rows
            );
        }

        // Total revision count
        $total = RuntimeRevision::where('domain_id', $domain->id)->count();
        $this->info("Total revisions: {$total}");

        return self::SUCCESS;
    }
}
