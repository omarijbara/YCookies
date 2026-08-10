<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Convenience wrapper: compile + verify + publish a single domain.
 *
 * This is shorthand for: manifest:rollout:execute --domains={domain}
 *
 * Usage:
 *   php artisan manifest:rollout:domain foo.com
 *   php artisan manifest:rollout:domain foo.com --force
 *   php artisan manifest:rollout:domain foo.com --json
 */
class ManifestRolloutDomain extends Command
{
    protected $signature = 'manifest:rollout:domain
                            {domain : The domain hostname to process}
                            {--force : Force recompile even if inputs unchanged}
                            {--json : Output result as JSON}';

    protected $description = 'Compile, verify, and publish a manifest revision for a single domain';

    public function handle(): int
    {
        $domain = $this->argument('domain');

        $args = ['--domains' => $domain];

        if ($this->option('force')) {
            $args['--force'] = true;
        }

        if ($this->option('json')) {
            $args['--json'] = true;
        }

        return $this->call('manifest:rollout:execute', $args);
    }
}
