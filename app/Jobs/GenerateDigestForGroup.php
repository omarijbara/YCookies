<?php

namespace App\Jobs;

use App\Models\AiSetting;
use App\Models\DailyTrafficReport;
use App\Models\Domain;
use App\Models\Group;
use App\Models\HealthCheckResult;
use App\Services\CrashReporter;
use App\Services\DigestSummaryEngine;
use App\Services\NotificationService;
use App\Services\ObservabilityService;
use App\Services\TelemetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * GenerateDigestForGroup — the core Phase 7 pipeline job.
 *
 * Pipeline (in order):
 * 1. Aggregate edge+RUM → raw KPIs
 * 2. Compute trend_json from previous reports
 * 3. Classify summary_status (deterministic, rule-based)
 * 4. Generate recommendations_json (deterministic, rule-based)
 * 5. Upsert per-domain rows + group summary row (domain_id=null)
 * 6. Optional AI brief (gated by AiSetting::is_active, NOT share_telemetry)
 * 7. Idempotent notification (email + Slack, gated by notified_at)
 * 8. Optional telemetry push (gated by AiSetting::share_telemetry, separate from AI)
 */
class GenerateDigestForGroup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $groupId,
        public Carbon $date,
    ) {
        $this->queue = 'default';
    }

    public function handle(
        ObservabilityService $obs,
        NotificationService $notify,
    ): void {
        $group = Group::find($this->groupId);
        if (!$group) {
            Log::warning('[digest] Group not found', ['group_id' => $this->groupId]);
            return;
        }

        $from = $this->date->copy()->startOfDay();
        $to   = $this->date->copy()->endOfDay();

        Log::info('[digest] Starting digest', [
            'group' => $group->name,
            'date'  => $this->date->toDateString(),
        ]);

        // ── Step 1: Aggregate edge + RUM + alerts ──────────
        $kpis = $obs->buildDailyKpis($this->groupId, $from, $to);

        // ── Step 2-5: Per-domain rows ──────────────────────
        $domainNames = Domain::where('group_id', $this->groupId)
            ->pluck('name', 'id');

        foreach ($kpis['domains'] as $domainId => $domainKpis) {
            $trendJson = $this->computeTrends($this->groupId, $domainId, $domainKpis);
            $status = DigestSummaryEngine::classifyStatus($domainKpis);
            $recommendations = DigestSummaryEngine::generateRecommendations($domainKpis, $trendJson);

            // Enrich recommendations from latest health check (non-blocking)
            try {
                $latestCheck = HealthCheckResult::where('domain_id', $domainId)
                    ->orderByDesc('checked_at')
                    ->first();

                if ($latestCheck && $latestCheck->evidence) {
                    $enrichment = DigestSummaryEngine::enrichRecommendationsFromHealthCheck($latestCheck->evidence);
                    $recommendations = array_merge($recommendations, $enrichment);
                }
            } catch (\Throwable $e) {
                Log::debug('[digest] Health check enrichment skipped', [
                    'domain_id' => $domainId,
                    'error'     => $e->getMessage(),
                ]);
            }

            DailyTrafficReport::updateOrCreate(
                [
                    'group_id'    => $this->groupId,
                    'domain_id'   => $domainId,
                    'report_date' => $this->date->toDateString(),
                ],
                [
                    'total_requests'       => $domainKpis['total_requests'] ?? 0,
                    'edge_p95_latency_ms'  => $domainKpis['edge_p95_latency_ms'] ?? null,
                    'inject_rate'          => $domainKpis['inject_rate'] ?? null,
                    'banner_render_rate'   => $domainKpis['banner_render_rate'] ?? null,
                    'alert_count'          => $domainKpis['alert_count'] ?? 0,
                    'kpi_blob'             => $domainKpis,
                    'summary_status'       => $status,
                    'trend_json'           => $trendJson,
                    'recommendations_json' => $recommendations,
                ]
            );
        }

        // ── Step 5: Group summary row (domain_id = null) ───
        $summaryKpis = $kpis['summary'];
        $summaryTrends = $this->computeTrends($this->groupId, null, $summaryKpis);
        $summaryStatus = DigestSummaryEngine::classifyStatus($summaryKpis);
        $summaryRecs = DigestSummaryEngine::generateRecommendations($summaryKpis, $summaryTrends);

        $summaryRow = DailyTrafficReport::updateOrCreate(
            [
                'group_id'    => $this->groupId,
                'domain_id'   => null,
                'report_date' => $this->date->toDateString(),
            ],
            [
                'total_requests'       => $summaryKpis['total_requests'] ?? 0,
                'edge_p95_latency_ms'  => $summaryKpis['edge_p95_latency_ms'] ?? null,
                'inject_rate'          => $summaryKpis['inject_rate'] ?? null,
                'banner_render_rate'   => $summaryKpis['banner_render_rate'] ?? null,
                'alert_count'          => $summaryKpis['alert_count'] ?? 0,
                'kpi_blob'             => array_merge($summaryKpis, ['domains' => $kpis['domains']]),
                'summary_status'       => $summaryStatus,
                'trend_json'           => $summaryTrends,
                'recommendations_json' => $summaryRecs,
            ]
        );

        // ── Step 6: Optional AI brief (gated by is_active, NOT share_telemetry) ──
        $this->generateAiBrief($summaryRow, $summaryKpis, $kpis['alerts']);

        // ── Step 7: Idempotent notification ────────────────
        if (!$summaryRow->notified_at) {
            try {
                NotificationService::configureDynamicMailer();
                $this->sendDigestNotification($group, $summaryRow);
                $summaryRow->update(['notified_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning('[digest] Notification failed', [
                    'group' => $group->name,
                    'error' => $e->getMessage(),
                ]);
                CrashReporter::report($e, [
                    'level'  => 'warning',
                    'url'    => '/admin/traffic-alerts',
                    'source' => 'digest-notification',
                    'group'  => $group->name,
                ]);
            }
        }

        // ── Step 8: Optional telemetry push (separate from AI) ──
        $this->pushToImprove($summaryRow);

        Log::info('[digest] Digest complete', [
            'group'   => $group->name,
            'status'  => $summaryStatus,
            'domains' => count($kpis['domains']),
        ]);
    }

    // ── Trend computation ──────────────────────────────────

    /**
     * Compute trend deltas vs previous day and 7-day average.
     * Falls back gracefully when history is too short.
     */
    protected function computeTrends(int $groupId, ?int $domainId, array $currentKpis): array
    {
        $trends = [
            'vs_prev_day' => [],
            'vs_7d_avg'   => [],
        ];

        // Previous day
        $prevDay = DailyTrafficReport::where('group_id', $groupId)
            ->where('domain_id', $domainId)
            ->where('report_date', $this->date->copy()->subDay()->toDateString())
            ->first();

        if ($prevDay && ($prevDay->kpi_blob['total_requests'] ?? 0) > 0) {
            $prev = $prevDay->kpi_blob;
            $trends['vs_prev_day'] = $this->computeDeltas($currentKpis, $prev);
        }

        // 7-day average
        $past7 = DailyTrafficReport::where('group_id', $groupId)
            ->where('domain_id', $domainId)
            ->where('report_date', '>=', $this->date->copy()->subDays(7)->toDateString())
            ->where('report_date', '<', $this->date->toDateString())
            ->get();

        if ($past7->isNotEmpty()) {
            $avg = [
                'edge_p95_latency_ms' => $past7->avg('edge_p95_latency_ms'),
                'total_requests'      => $past7->avg('total_requests'),
                'inject_rate'         => $past7->avg('inject_rate'),
                'alert_count'         => $past7->avg('alert_count'),
            ];
            $trends['vs_7d_avg'] = $this->computeDeltas($currentKpis, $avg);
        }

        return $trends;
    }

    protected function computeDeltas(array $current, array $previous): array
    {
        $deltas = [];

        // p95 delta
        $curP95  = $current['edge_p95_latency_ms'] ?? null;
        $prevP95 = $previous['edge_p95_latency_ms'] ?? null;
        if ($curP95 !== null && $prevP95 !== null && $prevP95 > 0) {
            $deltas['p95_delta_pct'] = round((($curP95 - $prevP95) / $prevP95) * 100, 1);
        }

        // Request delta
        $curReq  = $current['total_requests'] ?? 0;
        $prevReq = $previous['total_requests'] ?? 0;
        if ($prevReq > 0) {
            $deltas['request_delta_pct'] = round((($curReq - $prevReq) / $prevReq) * 100, 1);
        }

        // Inject rate delta (absolute)
        $curInj  = $current['inject_rate'] ?? null;
        $prevInj = $previous['inject_rate'] ?? null;
        if ($curInj !== null && $prevInj !== null) {
            $deltas['inject_rate_delta'] = round($curInj - $prevInj, 2);
        }

        // Alert count delta
        $curAlerts  = $current['alert_count'] ?? 0;
        $prevAlerts = $previous['alert_count'] ?? 0;
        $deltas['alert_count_delta'] = $curAlerts - $prevAlerts;

        return $deltas;
    }

    // ── AI brief ───────────────────────────────────────────

    /**
     * Generate AI brief — gated by AiSetting::is_active, NOT share_telemetry.
     * Non-blocking: catches and logs on failure without affecting the digest.
     */
    protected function generateAiBrief(DailyTrafficReport $report, array $kpis, array $alerts): void
    {
        try {
            $settings = AiSetting::instance();
            if (!$settings->isConfigured()) {
                return;
            }

            $prompt = $this->buildAiPrompt($kpis, $alerts, $report->trend_json);

            $service = app(\App\Services\OpenRouterService::class);
            $brief = $service->ask($prompt);

            if ($brief) {
                $report->update(['ai_brief' => $brief]);
                Log::info('[digest] AI brief generated', ['group_id' => $this->groupId]);
            }
        } catch (\Throwable $e) {
            Log::warning('[digest] AI brief generation failed (non-blocking)', [
                'group_id' => $this->groupId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    protected function buildAiPrompt(array $kpis, array $alerts, ?array $trends): string
    {
        $kpiJson = json_encode($kpis, JSON_PRETTY_PRINT);
        $alertJson = json_encode($alerts, JSON_PRETTY_PRINT);
        $trendJson = json_encode($trends ?? [], JSON_PRETTY_PRINT);

        return <<<PROMPT
        You are YCoppilot, a proxy SRE analyst for YCookies (a cookie consent management proxy).
        Summarize these daily KPIs in plain language for a non-technical operator.

        Rules:
        - If stable, say so clearly
        - If p95 latency regressed >10%, highlight it
        - If banner render rate <5%, mention possible third-party CMP conflict
        - If 5xx errors are elevated, flag it with urgency
        - End with one short recommended action
        - Maximum 120 words in English

        KPIs:
        {$kpiJson}

        Trend deltas:
        {$trendJson}

        Active alerts (domain_id => count):
        {$alertJson}
        PROMPT;
    }

    // ── Notification ───────────────────────────────────────

    protected function sendDigestNotification(Group $group, DailyTrafficReport $report): void
    {
        $users = $group->users ?? collect();

        if (method_exists($group, 'users')) {
            $users = $group->users()->whereNotNull('email_verified_at')->get();
        }

        foreach ($users as $user) {
            try {
                $user->notify(new \App\Notifications\DailyTrafficDigestNotification($report));
            } catch (\Throwable $e) {
                Log::warning('[digest] Email notification failed for user', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        Log::info('[digest] Notification sent', [
            'group'      => $group->name,
            'user_count' => $users->count(),
        ]);
    }

    // ── Telemetry push ─────────────────────────────────────

    /**
     * Push anonymized digest to improve.ypsilon.dev.
     * Gated by share_telemetry — separate from AI enablement.
     */
    protected function pushToImprove(DailyTrafficReport $report): void
    {
        try {
            $settings = AiSetting::instance();
            if (!$settings->share_telemetry || empty($settings->telemetry_token)) {
                return;
            }

            TelemetryService::sendDigest($report);
        } catch (\Throwable $e) {
            Log::debug('[digest] Telemetry push failed (non-critical)', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
