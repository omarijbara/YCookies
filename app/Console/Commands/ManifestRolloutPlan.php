<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\RuntimeRevision;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\Publisher\RevisionPublisher;
use App\Runtime\Publisher\RevisionSigner;
use Illuminate\Console\Command;

/**
 * Artisan command: read-only rollout plan.
 *
 * Shows per-domain eligibility, current revision state, and whether
 * a compile/publish would occur — without writing anything.
 *
 * Usage:
 *   php artisan manifest:rollout:plan                          # All active domains
 *   php artisan manifest:rollout:plan --domains=foo.com,bar.com
 *   php artisan manifest:rollout:plan --eligible-only
 *   php artisan manifest:rollout:plan --json
 */
class ManifestRolloutPlan extends Command
{
    protected $signature = 'manifest:rollout:plan
                            {--domains= : Comma-separated domain hostnames}
                            {--eligible-only : Show only manifest-eligible domains}
                            {--json : Output as JSON array}';

    protected $description = 'Dry-run: show rollout eligibility and compile status for domains';

    public function handle(DomainCompiler $compiler): int
    {
        $domains = $this->resolveDomains();
        if ($domains === null) {
            return self::INVALID;
        }

        if ($domains->isEmpty()) {
            $this->warn('No domains matched the selection criteria.');
            return self::SUCCESS;
        }

        $results = [];

        foreach ($domains as $domain) {
            $results[] = $this->assessDomain($domain, $compiler);
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->renderTable($results);
        return self::SUCCESS;
    }

    /**
     * Assess a single domain's rollout readiness without writing.
     */
    protected function assessDomain(Domain $domain, DomainCompiler $compiler): array
    {
        $result = [
            'domain'            => $domain->name,
            'site_id'           => $domain->site_id,
            'is_active'         => (bool) $domain->is_active,
            'manifest_enabled'  => (bool) $domain->manifest_enabled,
            'eligible'          => false,
            'current_revision'  => null,
            'inputs_changed'    => null,
            'would_compile'     => false,
            'would_publish'     => false,
            'blocked_reason'    => null,
        ];

        // Check eligibility
        if (!$domain->is_active) {
            $result['blocked_reason'] = 'domain_inactive';
            return $result;
        }

        if (!$domain->cookieBar) {
            $result['blocked_reason'] = 'no_cookie_bar';
            return $result;
        }

        if (!$domain->manifest_enabled) {
            $result['blocked_reason'] = 'manifest_disabled';
            return $result;
        }

        // Domain is eligible for manifest compilation
        $result['eligible'] = true;

        // Check current revision state
        $activeRevision = $domain->activeRevision;
        if ($activeRevision) {
            $result['current_revision'] = $activeRevision->revision_number;
        }

        // Attempt compile (dry-run) to check if inputs changed
        try {
            $compileResult = $compiler->compile($domain);
            $result['would_compile'] = true;

            if ($activeRevision && $activeRevision->compile_inputs_hash === $compileResult->compileInputsHash) {
                $result['inputs_changed'] = false;
                $result['would_publish'] = false;
                $result['blocked_reason'] = 'no_changes_detected';
            } else {
                $result['inputs_changed'] = true;
                $result['would_publish'] = true;
            }
        } catch (\Throwable $e) {
            $result['would_compile'] = false;
            $result['blocked_reason'] = 'compile_error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Resolve domains from --domains flag or all active domains.
     */
    protected function resolveDomains(): ?\Illuminate\Support\Collection
    {
        $domainNames = $this->option('domains');

        if ($domainNames) {
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

        $query = Domain::withoutGlobalScope('tenant')->where('is_active', true);

        if ($this->option('eligible-only')) {
            $query->whereHas('cookieBar')
                  ->where('manifest_enabled', true);
        }

        return $query->get();
    }

    /**
     * Render the assessment table.
     */
    protected function renderTable(array $results): void
    {
        $eligible = collect($results)->where('eligible', true)->count();
        $wouldPublish = collect($results)->where('would_publish', true)->count();

        $this->info("═══ Manifest Rollout Plan ═══");
        $this->newLine();
        $this->info("Domains assessed: " . count($results) . "  |  Eligible: {$eligible}  |  Would publish: {$wouldPublish}");
        $this->newLine();

        $rows = array_map(fn ($r) => [
            $r['domain'],
            $r['site_id'] ? substr($r['site_id'], 0, 8) . '...' : '-',
            $r['manifest_enabled'] ? '✓' : '✗',
            $r['eligible'] ? '✓' : '✗',
            $r['current_revision'] ?? '-',
            match (true) {
                $r['would_publish']  => '✓ publish',
                $r['would_compile'] && !$r['would_publish'] => '≡ skip (unchanged)',
                default              => '✗ blocked',
            },
            $r['blocked_reason'] ?? '-',
        ], $results);

        $this->table(
            ['Domain', 'Site ID', 'Manifest', 'Eligible', 'Rev', 'Action', 'Reason'],
            $rows,
        );
    }
}
