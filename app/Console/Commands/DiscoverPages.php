<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\ScriptScannerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiscoverPages extends Command
{
    protected $signature = 'pages:discover {domain_id}';
    protected $description = 'Discover all pages for a domain and organize into page sets.';

    public function handle(): int
    {
        $domain = Domain::find($this->argument('domain_id'));
        if (!$domain) {
            $this->error("Domain not found.");
            return 1;
        }

        $this->info("Discovering pages for: {$domain->name}");

        try {
            $result = ScriptScannerService::discoverAndOrganize($domain);

            $this->info("Done: {$result['total']} pages → {$result['priority']} priority + {$result['sets']} sets of ~{$result['set_size']}");
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed: " . $e->getMessage());

            // Still update last_discovery_at so polling stops with an error indicator.
            // Use raw DB update to bypass DomainObserver (no config change).
            DB::table('domains')
                ->where('id', $domain->id)
                ->update([
                    'last_discovery_at' => now(),
                    'discovered_pages_count' => -1, // signals error
                ]);
            return 1;
        }
    }
}
