<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Group;
use App\Models\ConsentLog;
use Illuminate\Support\Facades\Log;

class PurgeConsentLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:purge-consent-logs
                            {--group= : Limit purge to domains of this group (tenant-safe from admin UI)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge GDPR consent logs strictly older than the group retention setting';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Consent Logs Purge...');

        $groupId = $this->option('group');
        $groups = $groupId
            ? Group::where('id', $groupId)->get()
            : Group::all();

        if ($groups->isEmpty()) {
            $this->warn('No matching group(s). Nothing to purge.');

            return self::SUCCESS;
        }
        $totalDeleted = 0;

        foreach ($groups as $group) {
            $retentionDays = $group->consent_retention_days ?? 365;
            $cutoffDate = now()->subDays($retentionDays);

            $domainIds = $group->domains()->pluck('id')->toArray();

            if (empty($domainIds)) {
                continue;
            }

            // Using direct delete on the query builder for efficiency
            $deletedCount = ConsentLog::whereIn('domain_id', $domainIds)
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            $totalDeleted += $deletedCount;

            if ($deletedCount > 0) {
                Log::info("Purged {$deletedCount} consent logs for Group {$group->id} (older than {$retentionDays} days)");
            }
        }

        $this->info("Purge complete. Total deleted: {$totalDeleted}");
        
        return self::SUCCESS;
    }
}
