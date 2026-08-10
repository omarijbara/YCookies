<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Service;
use App\Models\CookieGroup;
use Illuminate\Support\Facades\File;

class ImportTemplates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:import:templates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports pre-configured service templates into the global database structure';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = database_path('services/templates.json');

        if (!File::exists($path)) {
            $this->error("Templates library not found at {$path}");
            return Command::FAILURE;
        }

        $templates = json_decode(File::get($path), true);
        $imported = 0;

        foreach ($templates as $template) {
            // Find or stub the global parent group
            $group = CookieGroup::firstOrCreate(
                ['key' => $template['group']],
                [
                    'name' => ucfirst($template['group']),
                    'description' => "Global {$template['group']} categorization",
                    'is_required' => ($template['group'] === 'essential')
                ]
            );

            // Create the generic service stub (not domain bound)
            // By default, our Service schema right now lacks tracking ID config natively - 
            // the IDs live in our `service_settings` pivoting on domains. So the global Service
            // is just a representation of the template itself.
            $service = Service::updateOrCreate(
                ['key' => $template['key']],
                [
                    'name' => $template['name'] ?? ucfirst($template['key']),
                    'purpose' => $template['description'] ?? "Global service mapping for {$template['key']}",
                    'provider' => explode(' ', $template['name'] ?? ucfirst($template['key']))[0],
                    'cookie_names' => json_encode($template['cookies'] ?? []),
                    'cookie_group_id' => $group->id,
                    'is_active' => true,
                ]
            );

            $imported++;
            $this->info("Imported template: {$service->name}");
        }

        $this->info("Successfully imported {$imported} templates.");
        return Command::SUCCESS;
    }
}
