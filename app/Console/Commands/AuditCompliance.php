<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Domain;

class AuditCompliance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:audit:compliance {domain} {--export=csv}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates a compliance audit report for the specified domain';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $domainName = $this->argument('domain');
        $domain = Domain::where('name', $domainName)
            ->with(['cookieGroups.services'])
            ->first();

        if (!$domain) {
            $this->error("Domain {$domainName} not found.");
            return Command::FAILURE;
        }

        // Just output a simple console table for now
        $this->info("Generating Compliance Audit for {$domain->name}...");

        $activeServices = [];
        foreach ($domain->cookieGroups as $group) {
            foreach ($group->services as $service) {
                $activeServices[] = [$group->name, $service->name, $service->is_preselected ? 'Yes' : 'No'];
            }
        }

        $this->table(['Cookie Group', 'Active Service', 'Pre-selected'], $activeServices);

        if ($this->option('export') === 'csv') {
            $filename = "{$domain->name}_audit_" . now()->format('Y_m_d_His') . ".csv";
            $path = storage_path("app/public/audits/{$filename}");
            @mkdir(dirname($path), 0755, true);

            $file = fopen($path, 'w');
            fputcsv($file, ['Cookie Group', 'Active Service', 'Pre-selected']);
            foreach ($activeServices as $row) {
                fputcsv($file, $row);
            }
            fclose($file);

            $this->info("Audit exported successfully to: {$path}");
        }

        return Command::SUCCESS;
    }
}
