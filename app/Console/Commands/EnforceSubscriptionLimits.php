<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Group;

class EnforceSubscriptionLimits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:enforce-limits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enforce active subscription quotas, disabling proxy on excess domains.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $groups = Group::all();
        $totalDisabled = 0;

        foreach ($groups as $group) {
            $limit = $group->domain_limit;
            
            // Get all domains for this group with proxy enabled, ordered by oldest first
            $domains = $group->domains()
                ->where('proxy_enabled', true)
                ->orderBy('created_at', 'asc')
                ->get();
                
            $activeCount = $domains->count();

            if ($activeCount > $limit) {
                // Determine how many we need to disable
                // Keep the oldest domains up to the limit
                $excessDomains = $domains->slice($limit);

                foreach ($excessDomains as $domain) {
                    $domain->update([
                        'proxy_enabled' => false,
                    ]);
                    $totalDisabled++;
                    $this->info("Disabled proxy for domain #{$domain->id} (Group #{$group->id}, Limit: {$limit})");
                }
            }
        }

        $this->info("Enforcement complete. Disabled proxy for {$totalDisabled} excess domain(s).");
    }
}
