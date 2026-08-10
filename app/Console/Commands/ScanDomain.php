<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Domain;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\File;

/**
 * @deprecated This command is a legacy prototype.
 *             Use the queue job ScanDomainCookies via `ycookies:run-scans` instead.
 *             This command does NOT create ScanResult records, does NOT use ScriptScannerService,
 *             and does NOT interact with DomainPageSet. It exists only for historical reference.
 */
class ScanDomain extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:scan:domain {domain?} {--deep} {--screenshots}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scans a domain for cookies and scripts using headless Chrome, categorized via fuzzy matching.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->warn('⚠️  DEPRECATED: This command is a legacy prototype. Use `ycookies:run-scans` for production scanning.');

        $domainName = $this->argument('domain');

        if (!$domainName) {
            $domainName = $this->ask('Please enter the domain name (e.g., example.com)');
        }

        $domain = Domain::where('name', $domainName)->first();

        if (!$domain) {
            if ($this->confirm("Domain {$domainName} not found in database. Create it?", true)) {
                $group = \App\Models\Group::firstOrCreate(['name' => 'Default Group']);
                $domain = Domain::create([
                    'name' => $domainName,
                    'site_id' => uniqid(),
                    'group_id' => $group->id,
                    'scan_frequency' => 'weekly'
                ]);
            } else {
                return Command::FAILURE;
            }
        }

        $this->info("Initiating Headless Chrome Scan for: {$domain->name}...");
        $this->info("This might take a few moments depending on network latency.\n");

        try {
            // Setup Browsershot
            $url = "https://" . ltrim($domain->name, 'https://');

            $jsonSchema = Browsershot::url($url)
                ->waitUntilNetworkIdle() // wait until network requests settle
                ->windowSize(1440, 900)
                ->evaluate('JSON.stringify({
                    cookies: document.cookie ? document.cookie.split(";").map(c => c.trim().split("=")[0]) : [],
                    scripts: Array.from(document.scripts).map(s => s.src).filter(Boolean),
                    iframes: Array.from(document.querySelectorAll("iframe")).map(i => i.src).filter(Boolean)
                })');

            $data = json_decode($jsonSchema, true);

            $this->processFindings($domain, $data);

            if ($this->option('screenshots')) {
                $ssPath = storage_path("app/public/scans/{$domain->id}_screenshot.jpg");
                @mkdir(dirname($ssPath), 0755, true);

                $this->info("Taking viewport screenshot...");
                Browsershot::url($url)->save($ssPath);
                $this->info("Screenshot saved: {$ssPath}");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("BrowserScan Failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function processFindings(Domain $domain, array $findings)
    {
        $this->info("==== SCAN RESULTS ====");
        $this->line("Cookies Count: " . count($findings['cookies']));
        $this->line("Scripts Count: " . count($findings['scripts']));
        $this->line("Iframes Count: " . count($findings['iframes']));
        $this->line("======================\n");

        $templates = $this->getTemplateLibrary();

        $this->info("Analyzing Signatures via Levenshtein Fuzzy Matcher...\n");

        $matches = [];

        // Match Scripts
        foreach ($findings['scripts'] as $scriptSrc) {
            $matchedTemplate = $this->fuzzyMatch($scriptSrc, $templates);
            if ($matchedTemplate) {
                $matches[$matchedTemplate['name']] = 'Script: ' . parse_url($scriptSrc, PHP_URL_HOST);
            }
        }

        // Match Iframes
        foreach ($findings['iframes'] as $iframeSrc) {
            $matchedTemplate = $this->fuzzyMatch($iframeSrc, $templates);
            if ($matchedTemplate) {
                $matches[$matchedTemplate['name']] = 'Iframe: ' . parse_url($iframeSrc, PHP_URL_HOST);
            }
        }

        // Output Discovered Matches
        if (empty($matches)) {
            $this->warn("No known templates matched the discovered payloads.");
        } else {
            $this->info("Matched Templates:");
            foreach ($matches as $templateName => $source) {
                $this->line("<fg=green>✔ {$templateName}</> (Triggered by {$source})");
            }
        }

        // We can optionally auto-attach these matches to the Domain via API or DB pivot
        // For Phase 5, the CLI primarily reveals these mappings.
    }

    protected function getTemplateLibrary()
    {
        $path = database_path('services/templates.json');
        if (!File::exists($path)) return [];
        return json_decode(File::get($path), true);
    }

    protected function fuzzyMatch($url, $templates)
    {
        $parsedHost = parse_url($url, PHP_URL_HOST);
        if (!$parsedHost) return false;

        // Remove www.
        $parsedHost = preg_replace('/^www\./', '', $parsedHost);

        foreach ($templates as $template) {
            if (empty($template['hosts'])) continue;

            foreach ($template['hosts'] as $host) {
                // Exact Match
                if (strpos($parsedHost, $host) !== false) {
                    return $template;
                }
                // Levenshtein (Fuzzy) - strict distance < 3 characters
                if (levenshtein($parsedHost, $host) < 3) {
                    return $template;
                }
            }
        }

        return false;
    }
}
