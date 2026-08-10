<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Runtime\Consumer\RevisionResolver;
use Illuminate\Console\Command;

/**
 * Operator convenience: invalidate the resolver cache for a given domain.
 *
 * Usage:
 *   php artisan manifest:resolver:invalidate duftz.de
 *   php artisan manifest:resolver:invalidate --all
 */
class ManifestResolverInvalidate extends Command
{
    protected $signature = 'manifest:resolver:invalidate
                            {domain? : The domain hostname to invalidate}
                            {--all : Invalidate all manifest-enabled domains}';

    protected $description = 'Invalidate the resolver cache for a domain (forces re-read from DB on next request)';

    public function handle(RevisionResolver $resolver): int
    {
        if ($this->option('all')) {
            return $this->invalidateAll($resolver);
        }

        $domainName = $this->argument('domain');
        if (!$domainName) {
            $this->error('Provide a domain name or use --all.');
            return self::INVALID;
        }

        $resolver->invalidate($domainName);
        $this->info("✓ Resolver cache invalidated for: {$domainName}");
        $this->line("  Next request will re-read from DB and verify signature.");

        return self::SUCCESS;
    }

    protected function invalidateAll(RevisionResolver $resolver): int
    {
        $domains = \App\Models\Domain::withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->where('manifest_enabled', true)
            ->pluck('name');

        if ($domains->isEmpty()) {
            $this->warn('No manifest-enabled domains found.');
            return self::SUCCESS;
        }

        foreach ($domains as $name) {
            $resolver->invalidate($name);
            $this->line("  ✓ {$name}");
        }

        $this->info("Invalidated {$domains->count()} domain(s).");
        return self::SUCCESS;
    }
}
