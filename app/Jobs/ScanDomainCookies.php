<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\ScanResult;

use App\Services\OutboundWebhookDispatcher;
use App\Services\ScriptScannerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ScanDomainCookies implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $domain;

    /**
     * Max execution time per job (5 minutes). Prevents stuck scans
     * from blocking the queue worker indefinitely.
     */
    public $timeout = 300;

    /**
     * Retry once after 60 seconds if the job fails.
     */
    public $tries = 2;
    public $backoff = 60;

    /**
     * Unique lock: prevent duplicate scan jobs for the same domain.
     * If a scan is already queued/running for this domain, skip.
     */
    public $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'scan-domain-' . $this->domain->id;
    }

    /**
     * Concurrency throttle: only 2 scan jobs run at the same time
     * across all domains. This prevents the scanner from consuming
     * all server resources and starving the proxy.
     */
    public function middleware(): array
    {
        return [
            new RateLimited('scanner'),
            (new WithoutOverlapping('scan-domain-' . $this->domain->id))->expireAfter(300),
        ];
    }

    public function __construct(Domain $domain)
    {
        $this->domain = $domain;
        // Scans must run on the scanner queue: its dedicated worker uses the
        // Chromium-equipped image (Dockerfile.laravel target "scanner") that
        // deep scans require, and is resource-capped so Puppeteer cannot
        // starve the proxy. On the default queue the deep-scan phase is
        // silently skipped on Chromium-less containers.
        $this->onQueue('scanner');
    }

    /**
     * Execute the job — performs a scan with priority pages + next page set.
     */
    public function handle(): void
    {
        Log::info("Starting automated scan for domain: {$this->domain->name}");

        try {
            $baseUrl = 'https://' . preg_replace('#^https?://#', '', $this->domain->name);
            $domainHost = parse_url($baseUrl, PHP_URL_HOST);

            // ─── Build page list: Priority Pages + Next Set ───
            $pages = [];
            $manualPages = $this->domain->scan_pages ?? [];
            $source = 'scheduled';
            $scanStages = [];
            $setInfo = null;

            // 1. Always include priority pages
            $priorityPages = $this->domain->allPriorityPages();
            if (!empty($priorityPages)) {
                $pages = array_merge($pages, $priorityPages);
            }

            // 2. Check for page sets (set-based rotation)
            $nextSet = $this->domain->nextPageSet();

            if ($nextSet) {
                $extraChunks = ScriptScannerService::rebalancePageSet(
                    $nextSet,
                    ScriptScannerService::scheduledSetChunkSize(),
                );
                if ($extraChunks > 0) {
                    $nextSet = $nextSet->fresh();
                    Log::info("Rebalanced oversized page set for {$this->domain->name}: set {$nextSet->set_index} split into " . ($extraChunks + 1) . " calmer chunks.");
                }

                // Set-based scanning mode
                $setPages = $nextSet->pages ?? [];
                $pages = array_merge($pages, $setPages);
                $source = 'set-' . $nextSet->set_index;
                $setInfo = [
                    'set_index' => $nextSet->set_index,
                    'set_pages' => count($setPages),
                    'cycle' => $nextSet->cycle_number,
                    'total_sets' => $this->domain->pageSets()
                        ->where('cycle_number', $this->domain->current_cycle)
                        ->count(),
                ];
                $scanStages['set'] = [
                    'status' => 'success',
                    'set_index' => $nextSet->set_index,
                    'set_pages' => count($setPages),
                    'priority_pages' => count($priorityPages),
                    'chunk_size' => ScriptScannerService::scheduledSetChunkSize(),
                ];
            } elseif (!empty($manualPages)) {
                // Fallback: manual pages
                $pages = array_merge($pages, array_map(function ($page) use ($baseUrl) {
                    $page = trim($page);
                    if (str_starts_with($page, '/')) return rtrim($baseUrl, '/') . $page;
                    if (!str_starts_with($page, 'http')) return rtrim($baseUrl, '/') . '/' . ltrim($page, '/');
                    return $page;
                }, $manualPages));
                $source = 'scheduled';
                $scanStages['discovery'] = ['status' => 'success', 'count' => count($pages), 'source' => 'manual'];
            } else {
                // Fallback: smart-sampled auto-discovery
                try {
                    $pages = array_merge($pages, ScriptScannerService::discoverPages($baseUrl, $domainHost));
                    $scanStages['discovery'] = ['status' => 'success', 'count' => count($pages), 'source' => 'auto'];
                } catch (\Exception $e) {
                    if (empty($pages)) $pages = [$baseUrl];
                    $scanStages['discovery'] = ['status' => 'error', 'error' => $e->getMessage(), 'count' => count($pages)];
                }
            }

            // Deduplicate pages
            $pages = array_values(array_unique($pages));
            if (empty($pages)) $pages = [$baseUrl];

            // ─── Scan each page ───
            $allUrls = [];
            $scanLog = [];
            $totalPages = count($pages);
            foreach ($pages as $index => $pageUrl) {
                $entry = ['url' => $pageUrl, 'status' => 'success', 'scripts' => 0, 'time' => 0];
                $start = microtime(true);
                try {
                    $httpUrls = ScriptScannerService::httpScan($pageUrl, $domainHost);
                    $allUrls = array_merge($allUrls, $httpUrls);
                    $entry['scripts'] = count($httpUrls);
                    $entry['time'] = round(microtime(true) - $start, 2);
                } catch (\Exception $e) {
                    $entry['status'] = 'error';
                    $entry['error'] = $e->getMessage();
                    $entry['time'] = round(microtime(true) - $start, 2);
                }
                $scanLog[] = $entry;

                if ($index < ($totalPages - 1)) {
                    ScriptScannerService::pauseBetweenScheduledRequests();
                }
            }

            $allUrls = array_unique($allUrls);
            $scanStages['http'] = ['status' => 'success', 'count' => count($allUrls), 'pages_scanned' => count($pages)];

            // Deep scan homepage
            if (ScriptScannerService::scheduledDeepScanEnabled()) {
                try {
                    $deepUrls = ScriptScannerService::deepScan($baseUrl, $domainHost);
                    $allUrls = array_unique(array_merge($allUrls, $deepUrls));
                    $scanStages['deep'] = ['status' => 'success', 'count' => count($deepUrls)];
                } catch (\Exception $e) {
                    $scanStages['deep'] = ['status' => 'skipped', 'count' => 0, 'error' => $e->getMessage()];
                }
            } else {
                $scanStages['deep'] = [
                    'status' => 'skipped',
                    'count' => 0,
                    'reason' => 'disabled_in_scheduled_lightweight_mode',
                ];
            }

            // ─── Categorize and save ───
            $categorized = ScriptScannerService::categorize($this->domain, $allUrls);

            $scanResult = ScanResult::create([
                'domain_id' => $this->domain->id,
                'domain_name' => $this->domain->name,
                'scanned_at' => now(),
                'source' => $source,
                'total_scripts' => count($categorized['raw']),
                'protected_count' => count($categorized['protected']),
                'suggested_count' => count($categorized['suggested']),
                'unknown_count' => count($categorized['unknown']),
                'pages_scanned_count' => count($pages),
                'pages_scanned' => $pages,
                'scan_log' => $scanLog,
                'scan_stages' => $scanStages,
                'protected_scripts' => $categorized['protected'],
                'suggested_scripts' => $categorized['suggested'],
                'unknown_scripts' => $categorized['unknown'],
                'raw_scripts' => $categorized['raw'],
            ]);

            OutboundWebhookDispatcher::dispatchScanCompleted($this->domain, $scanResult);

            // ─── Advance page set tracking ───
            if ($nextSet) {
                $nextSet->update([
                    'last_scanned_at' => now(),
                    'scan_result_id' => $scanResult->id,
                ]);

                // Check if all sets in current cycle are complete
                $remainingSets = $this->domain->pageSets()
                    ->where('cycle_number', $this->domain->current_cycle)
                    ->whereNull('last_scanned_at')
                    ->count();

                if ($remainingSets === 0) {
                    // All sets scanned — start new cycle.
                    // Use raw DB update to bypass DomainObserver (no config change).
                    $newCycle = ($this->domain->current_cycle ?? 1) + 1;
                    DB::table('domains')
                        ->where('id', $this->domain->id)
                        ->update([
                            'current_cycle' => $newCycle,
                            'current_set_index' => 0,
                        ]);

                    // Reset all sets for new cycle
                    $this->domain->pageSets()
                        ->where('cycle_number', $this->domain->current_cycle)
                        ->update([
                            'cycle_number' => $newCycle,
                            'last_scanned_at' => null,
                            'scan_result_id' => null,
                        ]);

                    Log::info("Cycle complete for {$this->domain->name}. Starting cycle {$newCycle}.");
                } else {
                    DB::table('domains')
                        ->where('id', $this->domain->id)
                        ->update([
                            'current_set_index' => $nextSet->set_index + 1,
                        ]);
                }
            }

            // Update domain last_scan_at (raw DB, no observer)
            DB::table('domains')
                ->where('id', $this->domain->id)
                ->update(['last_scan_at' => now()]);

            $totalFound = count($categorized['raw']);
            Log::info("Automated scan complete for {$this->domain->name}: {$totalFound} scripts found across " . count($pages) . " pages" . ($setInfo ? " (Set {$setInfo['set_index']}/{$setInfo['total_sets']}, Cycle {$setInfo['cycle']})" : ''));

            // Send email report if enabled
            if ($this->domain->report_enabled && $this->domain->report_email) {
                $this->sendReport($categorized, $scanStages, $pages);
            }

        } catch (\Exception $e) {
            Log::error("Automated scan failed for {$this->domain->name}: " . $e->getMessage());
            throw $e; // Re-throw so CrashReporter forwards it to Improve
        }
    }

    /**
     * Send scan report via email.
     */
    protected function sendReport(array $categorized, array $stages, array $pages): void
    {
        try {
            \App\Services\NotificationService::configureDynamicMailer();

            $results = [
                'protected' => $categorized['protected'],
                'suggested' => $categorized['suggested'],
                'unknown' => $categorized['unknown'],
                'raw' => $categorized['raw'],
                'stages' => $stages,
                'discoveredPages' => $pages,
            ];

            $html = ScriptScannerService::generateScanReportHtml($this->domain, $results);
            $subject = "🔍 Auto-Scan Report: {$this->domain->name} — " . now()->format('M d, Y');

            Mail::html($html, function ($message) use ($subject) {
                $smtp = \App\Models\SmtpSetting::instance();
                $message->to($this->domain->report_email)
                    ->subject($subject)
                    ->from($smtp->from_address ?: 'noreply@ycookies.test', $smtp->from_name ?: 'YCookies Scanner');
            });

            Log::info("Scan report emailed to {$this->domain->report_email}");
        } catch (\Exception $e) {
            Log::warning("Failed to send scan report for {$this->domain->name}: " . $e->getMessage());
            \App\Services\CrashReporter::report($e);
        }
    }
}
