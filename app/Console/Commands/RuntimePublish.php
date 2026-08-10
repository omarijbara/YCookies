<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Runtime\Compiler\DomainCompiler;
use App\Runtime\Publisher\RevisionPublisher;
use Illuminate\Console\Command;

/**
 * Artisan command: manually compile and publish a runtime revision.
 *
 * Usage:
 *   php artisan runtime:publish duftz.de          # Single domain
 *   php artisan runtime:publish --all             # All manifest-enabled domains
 *   php artisan runtime:publish duftz.de --force  # Recompile even if unchanged
 */
class RuntimePublish extends Command
{
    protected $signature = 'runtime:publish
                            {domain? : Domain hostname to compile}
                            {--all : Compile all manifest-enabled domains}
                            {--force : Force recompile even if inputs unchanged}';

    protected $description = 'Compile and publish a runtime manifest revision';

    public function handle(DomainCompiler $compiler, RevisionPublisher $publisher): int
    {
        if ($this->option('all')) {
            return $this->publishAll($compiler, $publisher);
        }

        $domainName = $this->argument('domain');
        if (!$domainName) {
            $this->error('Provide a domain name or use --all');
            return self::FAILURE;
        }

        return $this->publishDomain($domainName, $compiler, $publisher);
    }

    protected function publishDomain(string $domainName, DomainCompiler $compiler, RevisionPublisher $publisher): int
    {
        $domain = Domain::withoutGlobalScope('tenant')
            ->where('name', $domainName)
            ->first();

        if (!$domain) {
            $this->error("Domain '{$domainName}' not found");
            return self::FAILURE;
        }

        $this->info("Compiling {$domainName}...");

        try {
            $result = $compiler->compile($domain);

            // Check for no-op (unless forced)
            if (!$this->option('force')) {
                $lastRevision = $domain->activeRevision;
                if ($lastRevision && $lastRevision->compile_inputs_hash === $result->compileInputsHash) {
                    $this->warn("No changes detected for {$domainName} (inputs hash unchanged). Use --force to recompile.");
                    return self::SUCCESS;
                }
            }

            $revision = $publisher->publish($domain, $result);
            $publisher->postPublishAccelerate($domain, $revision);

            $this->info("✓ Published revision {$revision->revision_number} for {$domainName}");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Revision', $revision->revision_number],
                    ['Schema', $revision->schema_version],
                    ['Manifest Hash', substr($revision->manifest_hash, 0, 16) . '...'],
                    ['Base Hash', substr($revision->base_artifact_hash, 0, 16) . '...'],
                    ['Overlays', $revision->overlays()->count()],
                    ['Published At', $revision->published_at->toDateTimeString()],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to compile/publish {$domainName}: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function publishAll(DomainCompiler $compiler, RevisionPublisher $publisher): int
    {
        $domains = Domain::withoutGlobalScope('tenant')
            ->where('manifest_enabled', true)
            ->get();

        if ($domains->isEmpty()) {
            $this->warn('No manifest-enabled domains found');
            return self::SUCCESS;
        }

        $this->info("Publishing {$domains->count()} domain(s)...");
        $bar = $this->output->createProgressBar($domains->count());

        $success = 0;
        $failed = 0;

        foreach ($domains as $domain) {
            try {
                $result = $compiler->compile($domain);
                $revision = $publisher->publish($domain, $result);
                $publisher->postPublishAccelerate($domain, $revision);
                $success++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("  Failed: {$domain->name}: {$e->getMessage()}");
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Published: {$success}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
