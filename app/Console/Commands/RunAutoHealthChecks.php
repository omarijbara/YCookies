<?php

namespace App\Console\Commands;

use App\Jobs\RunHealthCheck;
use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunAutoHealthChecks extends Command
{
    protected $signature = 'ycookies:run-health-checks';

    protected $description = 'Dispatch health checks for all enabled proxy domains that are due for a check.';

    public function handle(): void
    {
        $domains = Domain::where('health_check_enabled', true)
            ->where('health_check_mode', 'scheduler')
            ->where('is_active', true)
            ->where('proxy_enabled', true)
            ->withCount(['healthCheckResults as today_scans_count' => function ($query) {
                $query->whereDate('checked_at', now()->toDateString());
            }])
            ->get();

        $dispatchedCount = 0;

        $this->info("Found {$domains->count()} domains with scheduler auto-checking enabled. Checking limits and intervals...");

        foreach ($domains as $domain) {
            $lastCheckedAt = $domain->last_health_check_at;
            $intervalMinutes = $domain->health_check_interval_minutes ?? 60;
            $maxScans = $domain->health_check_max_per_day ?? 24;
            $scansToday = $domain->today_scans_count ?? 0;
            $shouldRun = false;

            if ($scansToday < $maxScans) {
                if (!$lastCheckedAt) {
                    $shouldRun = true;
                } else {
                    $shouldRun = $lastCheckedAt->diffInMinutes(now()) >= $intervalMinutes;
                }
            }

            if ($shouldRun) {
                dispatch(new RunHealthCheck($domain, 'scheduled'));

                // Mark as checked immediately to prevent duplicate dispatches.
                // Use raw DB update to bypass DomainObserver — this timestamp
                // doesn't affect proxy config, so no config_version bump needed.
                DB::table('domains')
                    ->where('id', $domain->id)
                    ->update(['last_health_check_at' => now()]);

                $this->line("Dispatched health check for: {$domain->name} (Interval: {$intervalMinutes}m, {$scansToday}/{$maxScans} limit)");
                $dispatchedCount++;
            }
        }

        $this->info("Dispatched {$dispatchedCount} health check jobs.");
    }
}
