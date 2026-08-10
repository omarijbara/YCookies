<?php

namespace App\Console\Commands;

use App\Services\GvlService;
use Illuminate\Console\Command;

class UpdateGvl extends Command
{
    protected $signature = 'tcf:update-gvl';

    protected $description = 'Fetch and cache the latest IAB Global Vendor List (GVL) for TCF v2.2';

    public function handle(GvlService $gvlService): int
    {
        $this->info('Fetching IAB Global Vendor List...');

        $gvl = $gvlService->refresh();

        if (!$gvl) {
            $this->error('Failed to fetch GVL. Check logs for details.');
            return self::FAILURE;
        }

        $meta = $gvlService->meta();

        $this->info("✅ GVL updated successfully:");
        $this->line("   Version:  {$meta['version']}");
        $this->line("   Vendors:  {$meta['vendor_count']}");
        $this->line("   Checksum: {$meta['checksum']}");
        $this->line("   Fetched:  {$meta['fetched_at']}");

        return self::SUCCESS;
    }
}
