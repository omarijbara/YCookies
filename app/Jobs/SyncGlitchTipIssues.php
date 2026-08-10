<?php

namespace App\Jobs;

use App\Models\CrashReport;
use App\Models\GlitchTipSetting;
use App\Services\GlitchTipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGlitchTipIssues implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(GlitchTipService $service): void
    {
        $settings = GlitchTipSetting::instance();
        
        if (!$settings->isConfigured()) {
            return;
        }

        try {
            // Fetch the specific projects configured by the user
            $projectIds = is_array($settings->projects) ? $settings->projects : [];
            if (!empty($projectIds) && isset($projectIds[0]['project_id'])) {
                $projectIds = collect($projectIds)->pluck('project_id')->toArray();
            }

            // Grab the latest 100 issues from the GlitchTip API
            $issueResult = $service->getIssues(100, '', $projectIds);
            
            if ($issueResult['error']) {
                Log::warning('[SyncGlitchTip] API returned error: ' . $issueResult['error']);
                return;
            }

            $issues = $issueResult['issues'] ?? [];
            if (empty($issues)) {
                return;
            }

            // Sync project map to normalize project names
            $allProjects = $service->getProjects()['projects'] ?? [];
            $projectMap = collect($allProjects)->pluck('name', 'slug')->all();

            foreach ($issues as $issue) {
                // Determine project name for the source badge
                $issueSlug = is_array($issue['project']) ? ($issue['project']['slug'] ?? null) : strtolower(str_replace(' ', '-', $issue['project']));
                $projectName = $issueSlug && isset($projectMap[$issueSlug]) 
                    ? $projectMap[$issueSlug] 
                    : (is_array($issue['project']) ? ($issue['project']['name'] ?? 'Unknown') : $issue['project']);
                
                $sourceLabel = 'glitchtip-' . strtolower(str_replace(' ', '-', $projectName));

                // Fingerprint using GlitchTip's unique issue ID to guarantee stable upserts
                $fingerprint = hash('sha256', "glitchtip-issue-{$issue['id']}");

                $fullContext = [
                    'glitchtip_id'       => $issue['id'],
                    'glitchtip_short_id' => $issue['short_id'] ?? '',
                    'platform'           => $issue['platform'] ?? '',
                ];

                // Check if it already exists to append/update metrics
                $report = CrashReport::where('fingerprint', $fingerprint)->first();

                if ($report) {
                    $report->update([
                        'occurrence_count' => $issue['count'] ?? 1,
                        'last_seen_at'     => $issue['last_seen'] ? \Carbon\Carbon::parse($issue['last_seen']) : now(),
                        'resolved_at'      => $issue['status'] === 'resolved' ? now() : null,
                    ]);
                } else {
                    CrashReport::create([
                        'source'           => substr($sourceLabel, 0, 60),
                        'level'            => substr($issue['level'] ?? 'error', 0, 20),
                        'message'          => $issue['title'],
                        'stack_trace'      => $issue['culprit'] ?? null,
                        'context'          => $fullContext,
                        'fingerprint'      => $fingerprint,
                        'occurrence_count' => $issue['count'] ?? 1,
                        'first_seen_at'    => $issue['first_seen'] ? \Carbon\Carbon::parse($issue['first_seen']) : now(),
                        'last_seen_at'     => $issue['last_seen'] ? \Carbon\Carbon::parse($issue['last_seen']) : now(),
                        'resolved_at'      => $issue['status'] === 'resolved' ? now() : null,
                    ]);
                }
            }
            
            // Note: We purposely do NOT set telemetry_sent_at because we WANT these 
            // inserted rows to eventually be grabbed by the Telemetry Hub job.
        } catch (\Throwable $e) {
            Log::error('[SyncGlitchTip] Job crashed: ' . $e->getMessage());
        }
    }
}
