<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\ScriptScannerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiscoverDomainPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow up to 5 minutes for large sitemaps.
     */
    public int $timeout = 300;

    public function __construct(
        public Domain $domain
    ) {}

    public function handle(): void
    {
        Log::info("Starting page discovery for: {$this->domain->name}");

        try {
            $result = ScriptScannerService::discoverAndOrganize($this->domain);

            Log::info("Discovery complete for {$this->domain->name}: {$result['total']} pages → {$result['sets']} sets");
        } catch (\Exception $e) {
            Log::error("Discovery failed for {$this->domain->name}: " . $e->getMessage());
        }
    }
}
