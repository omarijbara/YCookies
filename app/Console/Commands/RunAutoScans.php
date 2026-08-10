<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;

class RunAutoScans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:run-scans';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch the cookie scanner for all auto-scan enabled domains.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $domains = \App\Models\Domain::where('scheduler_enabled', true)
                                     ->where('is_active', true)
                                     ->whereIn('scheduler_mode', ['traffic', 'scheduler', 'cronless', 'webcron'])
                                     ->get();
        $dispatchedCount = 0;

        $this->info("Found {$domains->count()} domains with auto-scan enabled. Checking schedules...");

        foreach ($domains as $domain) {
            $lastScannedAt = $domain->last_scan_at; // Neue Datenbank-Spalte -> last_scan_at
            $shouldScan = false;

            if (! $lastScannedAt) {
                $shouldScan = true;
            } else {
                $lockMinutes = max(60, $domain->lock_minutes ?? 60);
                $shouldScan = $lastScannedAt->diffInMinutes(now()) >= $lockMinutes;
            }

            if ($shouldScan) {
                // Check daily scan limit
                $maxPerDay = $domain->max_scans_per_day ?? 10;
                $todayCount = \App\Models\ScanResult::where('domain_id', $domain->id)
                    ->whereDate('scanned_at', today())
                    ->count();

                if ($todayCount >= $maxPerDay) {
                    $this->line("Skipped {$domain->name}: daily limit reached ({$todayCount}/{$maxPerDay})");
                    continue;
                }

                // Check group monthly scan limit
                $group = $domain->group;
                if ($group && !$group->canRunScan()) {
                    $this->line("Skipped {$domain->name}: group monthly limit reached ({$group->scans_this_month}/{$group->scan_limit})");
                    continue;
                }

                // Atomic stamp-and-check: prevents duplicate dispatch if two cron processes run simultaneously.
                // UPDATE only succeeds if last_scan_at is still in the "should scan" window.
                // Uses raw DB query to bypass DomainObserver (no config change).
                $lockMinutes = max(60, $domain->lock_minutes ?? 60);
                $stamped = DB::table('domains')
                    ->where('id', $domain->id)
                    ->where(function ($q) use ($lockMinutes) {
                        $q->whereNull('last_scan_at')
                          ->orWhere('last_scan_at', '<=', now()->subMinutes($lockMinutes));
                    })
                    ->update(['last_scan_at' => now()]);

                if ($stamped === 0) {
                    $this->line("Skipped {$domain->name}: already claimed by another process.");
                    continue;
                }

                dispatch(new \App\Jobs\ScanDomainCookies($domain));
                $this->line("Dispatched scanner for: {$domain->name} (Interval: {$domain->lock_minutes}m, Today: {$todayCount}/{$maxPerDay})");
                $dispatchedCount++;
            }
        }

        $this->info("Dispatched {$dispatchedCount} scanner jobs successfully.");
    }
}
