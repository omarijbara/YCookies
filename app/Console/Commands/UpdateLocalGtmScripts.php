<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ServiceSetting;
use App\Services\GtmDownloaderService;
use Illuminate\Support\Facades\Log;

class UpdateLocalGtmScripts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:update-local-gtm-scripts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Downloads and caches Google Tag Manager scripts locally for services with gtm_cache_locally enabled';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting local GTM script updates...');
        
        $settings = ServiceSetting::where('gtm_cache_locally', true)
            ->whereNotNull('gtm_id')
            ->where('gtm_id', '!=', '')
            ->get();

        $count = 0;
        foreach ($settings as $setting) {
            $this->line("Downloading GTM script for ID: {$setting->gtm_id} (Service ID: {$setting->service_id})");
            
            if (GtmDownloaderService::download($setting->gtm_id)) {
                $count++;
                // Touch the setting to update the updated_at timestamp, which we use as a cache buster
                $setting->touch();
                $this->info("Successfully updated {$setting->gtm_id}");
            } else {
                $this->error("Failed to update {$setting->gtm_id}");
            }
        }

        $this->info("Finished updating {$count} local GTM scripts.");
    }
}
