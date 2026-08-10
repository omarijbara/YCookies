<?php

namespace App\Console\Commands;

use App\Services\CrashReporter;
use Illuminate\Console\Command;

/**
 * Fire synthetic test errors through CrashReporter for every wired source.
 *
 * Usage:   php artisan crash-reporter:test
 *          php artisan crash-reporter:test --source=health-checker
 *
 * Each test error gets a unique fingerprint so they show as separate rows
 * in the Improve Ypsilon Error Reports dashboard.
 */
class TestCrashReporter extends Command
{
    protected $signature = 'crash-reporter:test {--source= : Fire a single source only}';
    protected $description = 'Send synthetic test errors to Improve Ypsilon for every CrashReporter source';

    /** All sources that are wired in the codebase. */
    protected array $sources = [
        'global-exception'   => ['level' => 'error',   'url' => '/up',                 'source' => 'laravel-ycookies'],
        'cookie-scanner'     => ['level' => 'error',   'url' => '/admin/cookie-scanner','source' => 'cookie-scanner'],
        'health-checker'     => ['level' => 'error',   'url' => '/admin/health-checker','source' => 'health-checker'],
        'digest-notification'=> ['level' => 'warning', 'url' => '/admin/traffic-alerts','source' => 'digest-notification'],
        'manifest-compiler'  => ['level' => 'error',   'url' => '/admin/installation',  'source' => 'manifest-compiler'],
        'coolify-sync'       => ['level' => 'error',   'url' => '/admin/settings',      'source' => 'coolify-sync'],
        'webhook-delivery'   => ['level' => 'error',   'url' => '/admin/settings',      'source' => 'webhook-delivery'],
        'telemetry-push'     => ['level' => 'warning', 'url' => '/admin/settings',      'source' => 'telemetry-push'],
        'node-proxy'         => ['level' => 'error',   'url' => '/api/proxy-errors',    'source' => 'node-proxy'],
    ];

    public function handle(): int
    {
        $only = $this->option('source');

        if ($only && !isset($this->sources[$only])) {
            $this->error("Unknown source: {$only}");
            $this->line("Available: " . implode(', ', array_keys($this->sources)));
            return Command::FAILURE;
        }

        $targets = $only ? [$only => $this->sources[$only]] : $this->sources;

        $this->info("🚀 Firing " . count($targets) . " test error(s) via CrashReporter...\n");

        foreach ($targets as $name => $ctx) {
            $exception = new \RuntimeException(
                "[TEST] Synthetic error from '{$name}' — fired at " . now()->toIso8601String()
            );

            CrashReporter::report($exception, $ctx);

            $this->line("  ✅ <info>{$name}</info> → dispatched to queue");
        }

        $this->newLine();
        $this->info("Done! Errors are queued via ForwardErrorsToImprove.");
        $this->info("They will POST to https://improve.ypsilon.dev/api/errors");
        $this->info("Check the dashboard: https://improve.ypsilon.dev/admin/error-reports");

        return Command::SUCCESS;
    }
}
