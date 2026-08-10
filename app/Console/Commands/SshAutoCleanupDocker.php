<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CoolifySetting;
use App\Services\ServerInfraService;
use Illuminate\Support\Facades\Log;

class SshAutoCleanupDocker extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ycookies:ssh-auto-cleanup';

    /**
     * The console command description.
     */
    protected $description = 'Automatically checks server disk space via SSH and runs Docker cleanup if threshold is exceeded';

    /**
     * Execute the console command.
     */
    public function handle(ServerInfraService $service)
    {
        $settings = CoolifySetting::instance();

        // Ensure SSH is configured and Auto-Cleanup is enabled
        if (!$settings->ssh_is_active || !$settings->ssh_auto_cleanup_enabled) {
            $this->info('SSH Auto-Cleanup is disabled or SSH is not configured.');
            return;
        }

        $threshold = $settings->ssh_auto_cleanup_threshold ?? 80;
        $intervalMinutes = $settings->ssh_auto_cleanup_interval ?? 60;

        // Ensure we don't run more frequently than the user-defined interval
        $lastRun = \Illuminate\Support\Facades\Cache::get('ssh_auto_cleanup_last_run', 0);
        if ((now()->timestamp - $lastRun) < ($intervalMinutes * 60) - 60) {
            $this->info("Skipping check. Interval is {$intervalMinutes} mins. Last run was recently.");
            return;
        }
        
        \Illuminate\Support\Facades\Cache::put('ssh_auto_cleanup_last_run', now()->timestamp);

        // Check actual host disk capacity via SSH
        $result = $service->executeServerCommand('df -h /', 'Disk space check', 10);

        if (!$result || !$result['success']) {
            $this->error('Failed to retrieve disk space via SSH.');
            Log::warning('[SSH-Auto-Cleanup] Failed to execute df -h via SSH.');
            return;
        }

        $output = $result['message'];
        
        // Parse the Use% from the `df -h /` output
        // Example line: overlay                  74.8G     36.7G     35.0G  51% /
        if (preg_match('/(\d+)%\s+\/$/m', $output, $matches)) {
            $currentUsage = (int) $matches[1];
            $this->info("Current disk usage: {$currentUsage}% (Threshold: {$threshold}%)");

            if ($currentUsage > $threshold) {
                $this->warn("Disk usage exceeded threshold! Triggering emergency Docker prune...");
                Log::warning("[SSH-Auto-Cleanup] Disk usage {$currentUsage}% exceeded threshold {$threshold}%. Triggering Docker prune via SSH.");
                
                $pruneResult = $service->triggerCleanup();
                
                if ($pruneResult['success']) {
                    $this->info("Emergency prune successful.");
                    Log::info("[SSH-Auto-Cleanup] Emergency prune completed:\n" . ($pruneResult['message'] ?? ''));
                } else {
                    $this->error("Emergency prune failed: " . ($pruneResult['message'] ?? 'Unknown error'));
                    Log::error("[SSH-Auto-Cleanup] Emergency prune failed:\n" . ($pruneResult['message'] ?? 'Unknown error'));
                }
            } else {
                $this->info('Disk space is healthy. No action required.');
            }
        } else {
            $this->error('Could not parse disk usage from df output.');
            Log::warning("[SSH-Auto-Cleanup] Could not parse disk usage. Raw output:\n{$output}");
        }
    }
}
