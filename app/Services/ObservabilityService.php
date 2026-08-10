<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\TrafficAlertState;
use App\Models\TrafficMetric;
use App\Models\TrafficRumEvent;
use App\Models\AiSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Observability Service — Rule-based anomaly detection with optional AI augmentation.
 *
 * Guarded autonomy model:
 * ✅ detect → classify → report → propose → alert
 * ❌ No auto-failover, no config mutation, no rollback
 *
 * Alert suppression:
 * - Dedup key: domain_id + alert_type
 * - Cool-down: severity-aware (critical=10min, warning=20min, info=30min)
 * - Evidence refreshed even while suppressed
 * - Recovery notification when metric returns to normal
 */
class ObservabilityService
{
    const WINDOW = 5;
    const BASELINE_WINDOW = 15;
    const MAX_P95_MS = 3000;
    const MAX_ERROR_RATE = 0.05;
    const MAX_INJECT_FAIL_RATE = 0.02;
    const BASELINE_DEGRADATION = 2.0;

    /**
     * Analyse traffic for a set of domain IDs.
     */
    public function analyse(array $domainIds): void
    {
        foreach ($domainIds as $domainId) {
            try {
                $this->analyseDomain($domainId);
            } catch (\Throwable $e) {
                Log::error('[observability] Failed to analyse domain', [
                    'domain_id' => $domainId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Analyse a single domain's recent traffic.
     */
    protected function analyseDomain(int $domainId): void
    {
        $domain = Domain::find($domainId);
        if (!$domain) return;

        $since = now()->subMinutes(self::WINDOW);
        $metrics = TrafficMetric::where('domain_id', $domainId)
            ->where('bucket', '>=', $since)
            ->get();

        if ($metrics->isEmpty()) return;

        $totalRequests = $metrics->sum('request_count');
        if ($totalRequests === 0) return;

        // ── Merge histograms ───────────────────────────────
        $mergedLatency = TrafficMetric::emptyHistogram();
        foreach ($metrics as $m) {
            $mergedLatency = TrafficMetric::mergeHistograms(
                $mergedLatency,
                $m->latency_histogram ?? TrafficMetric::emptyHistogram()
            );
        }

        $p50 = TrafficMetric::percentileFromHistogram($mergedLatency, 50);
        $p95 = TrafficMetric::percentileFromHistogram($mergedLatency, 95);
        $p99 = TrafficMetric::percentileFromHistogram($mergedLatency, 99);

        $total5xx = $metrics->sum('status_5xx');
        $total4xx = $metrics->sum('status_4xx');
        $errorRate5xx = $total5xx / $totalRequests;

        $injectAttempted = $metrics->sum('inject_attempted');
        $injectFailed = $metrics->sum('inject_failed');
        $injectFailRate = $injectAttempted > 0 ? $injectFailed / $injectAttempted : 0;

        // ── Rule checks ────────────────────────────────────
        $alerts = [];

        if ($p95 > self::MAX_P95_MS) {
            $alerts[] = [
                'type'     => 'high_latency',
                'severity' => 'warning',
                'message'  => "p95 latency is {$p95}ms (threshold: " . self::MAX_P95_MS . "ms), p99: {$p99}ms",
                'value'    => $p95,
            ];
        }

        if ($errorRate5xx > self::MAX_ERROR_RATE) {
            $pct = round($errorRate5xx * 100, 1);
            $alerts[] = [
                'type'     => 'high_5xx_rate',
                'severity' => $errorRate5xx > 0.15 ? 'critical' : 'warning',
                'message'  => "5xx error rate {$pct}% ({$total5xx}/{$totalRequests}) — threshold: " . (self::MAX_ERROR_RATE * 100) . "%",
                'value'    => $errorRate5xx,
            ];
        }

        if ($injectFailRate > self::MAX_INJECT_FAIL_RATE) {
            $pct = round($injectFailRate * 100, 1);
            $alerts[] = [
                'type'     => 'injection_failure',
                'severity' => 'warning',
                'message'  => "Script injection failed on {$pct}% of attempts ({$injectFailed}/{$injectAttempted})",
                'value'    => $injectFailRate,
            ];
        }

        // ── Baseline comparison ────────────────────────────
        $baselineStart = now()->subMinutes(self::WINDOW + self::BASELINE_WINDOW);
        $baselineEnd = now()->subMinutes(self::WINDOW);
        $baseline = TrafficMetric::where('domain_id', $domainId)
            ->whereBetween('bucket', [$baselineStart, $baselineEnd])
            ->get();

        if ($baseline->isNotEmpty()) {
            $baselineLatency = TrafficMetric::emptyHistogram();
            foreach ($baseline as $bm) {
                $baselineLatency = TrafficMetric::mergeHistograms(
                    $baselineLatency,
                    $bm->latency_histogram ?? TrafficMetric::emptyHistogram()
                );
            }
            $baselineP95 = TrafficMetric::percentileFromHistogram($baselineLatency, 95);
            $baselineRequests = $baseline->sum('request_count');
            $baseline5xxRate = $baselineRequests > 0
                ? $baseline->sum('status_5xx') / $baselineRequests : 0;

            if ($baselineP95 > 0 && $p95 > ($baselineP95 * self::BASELINE_DEGRADATION)) {
                $alerts[] = [
                    'type'     => 'latency_degradation',
                    'severity' => 'warning',
                    'message'  => "p95 {$p95}ms is " . round($p95 / $baselineP95, 1) . "x worse than baseline ({$baselineP95}ms)",
                    'value'    => $p95 / $baselineP95,
                ];
            }

            if ($baseline5xxRate > 0 && $errorRate5xx > ($baseline5xxRate * self::BASELINE_DEGRADATION)) {
                $alerts[] = [
                    'type'     => 'error_rate_degradation',
                    'severity' => 'warning',
                    'message'  => "5xx rate " . round($errorRate5xx * 100, 1) . "% is " . round($errorRate5xx / $baseline5xxRate, 1) . "x worse than baseline",
                    'value'    => $errorRate5xx / $baseline5xxRate,
                ];
            }
        }

        // ── Error breakdown ────────────────────────────────
        $errorBreakdown = [];
        foreach ($metrics as $m) {
            foreach (($m->error_codes ?? []) as $code => $count) {
                $errorBreakdown[$code] = ($errorBreakdown[$code] ?? 0) + $count;
            }
        }

        // Build evidence summary (shared across alert dispatch + suppression)
        $summary = [
            'window_minutes'   => self::WINDOW,
            'total_requests'   => $totalRequests,
            'p50_ms'           => $p50,
            'p95_ms'           => $p95,
            'p99_ms'           => $p99,
            '5xx_rate'         => $errorRate5xx,
            '4xx_count'        => $total4xx,
            'inject_fail_rate' => $injectFailRate,
            'error_codes'      => $errorBreakdown,
            'cache_hit_rate'   => ($metrics->sum('cache_hits') + $metrics->sum('cache_misses')) > 0
                ? $metrics->sum('cache_hits') / ($metrics->sum('cache_hits') + $metrics->sum('cache_misses'))
                : 0,
        ];

        // ── Alert suppression + dispatch ───────────────────
        $firedAlertTypes = collect($alerts)->pluck('type')->toArray();
        $this->processAlerts($domain, $alerts, $summary);

        // ── Recovery detection ─────────────────────────────
        $this->checkRecovery($domain, $firedAlertTypes);
    }

    /**
     * Process each alert through the suppression gate.
     *
     * States: open → suppressed → resolved
     * - New alert type → create as "open", dispatch
     * - Existing open/suppressed + in cool-down → refresh evidence, suppress
     * - Existing suppressed + past cool-down → re-open, dispatch
     */
    protected function processAlerts(Domain $domain, array $alerts, array $summary): void
    {
        if (empty($alerts)) return;

        $aiHints = null;
        $dispatchable = [];

        foreach ($alerts as $alert) {
            $existing = TrafficAlertState::where('domain_id', $domain->id)
                ->where('alert_type', $alert['type'])
                ->first();

            if (!$existing) {
                // ── Brand new alert → open + dispatch ──────
                $cooldown = TrafficAlertState::COOLDOWN_MINUTES[$alert['severity']]
                    ?? TrafficAlertState::COOLDOWN_MINUTES['warning'];

                TrafficAlertState::create([
                    'domain_id'        => $domain->id,
                    'alert_type'       => $alert['type'],
                    'state'            => TrafficAlertState::STATE_OPEN,
                    'severity'         => $alert['severity'],
                    'hit_count'        => 1,
                    'latest_value'     => $alert['value'],
                    'latest_message'   => $alert['message'],
                    'evidence_payload' => $summary,
                    'first_fired_at'   => now(),
                    'last_fired_at'    => now(),
                    'suppressed_until' => now()->addMinutes($cooldown),
                ]);

                $dispatchable[] = $alert;
                continue;
            }

            if ($existing->state === TrafficAlertState::STATE_RESOLVED) {
                // Was resolved, now returning -> handle like new but don't duplicate row
                $cooldown = TrafficAlertState::COOLDOWN_MINUTES[$alert['severity']]
                    ?? TrafficAlertState::COOLDOWN_MINUTES['warning'];
                
                $existing->update([
                    'state'            => TrafficAlertState::STATE_OPEN,
                    'severity'         => $alert['severity'],
                    'hit_count'        => 1,
                    'latest_value'     => $alert['value'],
                    'latest_message'   => $alert['message'],
                    'evidence_payload' => $summary,
                    'first_fired_at'   => now(),
                    'last_fired_at'    => now(),
                    'suppressed_until' => now()->addMinutes($cooldown),
                    'resolved_at'      => null,
                ]);

                $dispatchable[] = $alert;
                continue;
            }

            // ── Existing alert — always refresh evidence ───
            $existing->update([
                'hit_count'        => $existing->hit_count + 1,
                'latest_value'     => $alert['value'],
                'latest_message'   => $alert['message'],
                'evidence_payload' => $summary,
                'last_fired_at'    => now(),
                'severity'         => $alert['severity'], // severity may escalate
            ]);

            if ($existing->isSuppressed()) {
                // Still in cool-down → log but don't dispatch
                Log::debug('[observability] Alert suppressed', [
                    'domain'     => $domain->name,
                    'type'       => $alert['type'],
                    'hit_count'  => $existing->hit_count,
                    'suppressed_until' => $existing->suppressed_until->toIso8601String(),
                ]);
                continue;
            }

            // Cool-down expired → re-open and dispatch
            $cooldown = TrafficAlertState::COOLDOWN_MINUTES[$alert['severity']]
                ?? TrafficAlertState::COOLDOWN_MINUTES['warning'];

            $existing->update([
                'state'            => TrafficAlertState::STATE_OPEN,
                'suppressed_until' => now()->addMinutes($cooldown),
            ]);

            $dispatchable[] = $alert;
        }

        // ── Dispatch only non-suppressed alerts ────────────
        if (!empty($dispatchable)) {
            // AI augmentation (optional) — only for dispatchable alerts
            if ($this->isAiAvailable()) {
                try {
                    $context = $this->buildAiContext(
                        $domain, $dispatchable,
                        $summary['p95_ms'], $summary['p99_ms'],
                        $summary['5xx_rate'], $summary['inject_fail_rate'],
                        $summary['error_codes']
                    );
                    $aiHints = $this->askAi($context);
                } catch (\Throwable $e) {
                    Log::warning('[observability] AI augmentation failed', [
                        'domain' => $domain->name,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            $this->dispatchAlerts($domain, $dispatchable, $aiHints, $summary);
        }
    }

    /**
     * Check for alert recovery — emit "resolved" when metrics return to normal.
     *
     * Any open/suppressed alert type that was NOT fired this cycle
     * means the condition cleared → mark resolved + notify.
     */
    protected function checkRecovery(Domain $domain, array $firedAlertTypes): void
    {
        $activeAlerts = TrafficAlertState::where('domain_id', $domain->id)
            ->whereIn('state', [TrafficAlertState::STATE_OPEN, TrafficAlertState::STATE_SUPPRESSED])
            ->get();

        foreach ($activeAlerts as $alertState) {
            if (in_array($alertState->alert_type, $firedAlertTypes)) {
                continue; // still firing — not recovered
            }

            // Condition cleared → resolve
            $alertState->update([
                'state'       => TrafficAlertState::STATE_RESOLVED,
                'resolved_at' => now(),
            ]);

            Log::info('[observability] Alert resolved', [
                'domain'    => $domain->name,
                'type'      => $alertState->alert_type,
                'duration'  => $alertState->first_fired_at->diffForHumans(now(), true),
                'hit_count' => $alertState->hit_count,
            ]);
        }
    }

    // ── Helpers ────────────────────────────────────────────

    protected function isAiAvailable(): bool
    {
        try {
            $settings = AiSetting::instance();
            return $settings && $settings->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function buildAiContext(
        Domain $domain, array $alerts,
        int $p95, int $p99,
        float $errorRate5xx, float $injectFailRate,
        array $errorBreakdown,
    ): string {
        $alertSummary = collect($alerts)->map(fn ($a) => "- [{$a['severity']}] {$a['message']}")->join("\n");
        $errorCodes = collect($errorBreakdown)->map(fn ($count, $code) => "$code: $count")->join(', ');

        return <<<PROMPT
        Domain: {$domain->name}
        Metrics Window: last 5 minutes

        Alerts triggered:
        {$alertSummary}

        Key metrics:
        - p95 latency: {$p95}ms
        - p99 latency: {$p99}ms
        - 5xx error rate: {$errorRate5xx}
        - Injection failure rate: {$injectFailRate}
        - Error codes: {$errorCodes}

        This is a YCookies reverse proxy domain. Suggest likely root causes and quick diagnostic steps.
        Keep the response to 3-5 bullet points, actionable and specific.
        PROMPT;
    }

    protected function askAi(string $context): ?string
    {
        $service = app(\App\Services\OpenRouterService::class);
        return $service->ask($context);
    }

    protected function dispatchAlerts(Domain $domain, array $alerts, ?string $aiHints, array $summary): void
    {
        Log::warning('[observability] Traffic alerts for ' . $domain->name, [
            'alerts'   => $alerts,
            'ai_hints' => $aiHints,
            'summary'  => $summary,
        ]);

        try {
            $telemetry = app(TelemetryService::class);
            if (method_exists($telemetry, 'sendTrafficAlerts')) {
                $telemetry->sendTrafficAlerts($domain, $alerts, $aiHints);
            }
        } catch (\Throwable $e) {
            Log::debug('[observability] Telemetry send failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Daily Digest Aggregation ───────────────────────────

    /**
     * Build daily KPIs for a group by joining edge metrics + RUM + alerts.
     *
     * Returns:
     * [
     *   'domains' => [domain_id => [...kpis...]],
     *   'summary' => [...group-level aggregates...],
     *   'alerts'  => [domain_id => count],
     * ]
     */
    public function buildDailyKpis(int $groupId, Carbon $from, Carbon $to): array
    {
        $domainIds = Domain::where('group_id', $groupId)->pluck('id');

        if ($domainIds->isEmpty()) {
            return [
                'domains' => [],
                'summary' => $this->emptyKpiSet(),
                'alerts'  => [],
            ];
        }

        // ── Edge metrics: merge histograms per domain ─────────
        $edgeByDomain = [];
        foreach ($domainIds as $domainId) {
            $metrics = TrafficMetric::where('domain_id', $domainId)
                ->whereBetween('bucket', [$from, $to])
                ->get();

            if ($metrics->isEmpty()) {
                $edgeByDomain[$domainId] = $this->emptyEdgeKpis();
                continue;
            }

            $totalRequests   = $metrics->sum('request_count');
            $total5xx        = $metrics->sum('status_5xx');
            $htmlResponses   = $metrics->sum('html_responses');
            $injectAttempted = $metrics->sum('inject_attempted');
            $injectSucceeded = $metrics->sum('inject_succeeded');

            // Merge histograms
            $mergedLatency = TrafficMetric::emptyHistogram();
            foreach ($metrics as $m) {
                $mergedLatency = TrafficMetric::mergeHistograms(
                    $mergedLatency,
                    $m->latency_histogram ?? TrafficMetric::emptyHistogram()
                );
            }

            $edgeByDomain[$domainId] = [
                'total_requests'       => $totalRequests,
                'total_5xx'            => $total5xx,
                'error_rate_5xx'       => $totalRequests > 0 ? round($total5xx / $totalRequests, 4) : 0,
                'html_responses'       => $htmlResponses,
                'inject_attempted'     => $injectAttempted,
                'inject_succeeded'     => $injectSucceeded,
                'inject_rate'          => $injectAttempted > 0
                    ? round(($injectSucceeded / $injectAttempted) * 100, 2)
                    : null,
                'edge_p50_latency_ms'  => TrafficMetric::percentileFromHistogram($mergedLatency, 50),
                'edge_p95_latency_ms'  => TrafficMetric::percentileFromHistogram($mergedLatency, 95),
                'edge_p99_latency_ms'  => TrafficMetric::percentileFromHistogram($mergedLatency, 99),
                'cache_hits'           => $metrics->sum('cache_hits'),
                'cache_misses'         => $metrics->sum('cache_misses'),
            ];
        }

        // ── RUM metrics: banner render + DCL + injection confirmation ──
        $rumByDomain = [];
        foreach ($domainIds as $domainId) {
            $rum = TrafficRumEvent::where('domain_id', $domainId)
                ->whereBetween('bucket', [$from, $to])
                ->get();

            if ($rum->isEmpty()) {
                $rumByDomain[$domainId] = $this->emptyRumKpis();
                continue;
            }

            $totalPageviews    = $rum->sum('pageview_count');
            $bannerExpected    = $rum->sum('banner_expected_count');
            $bannerRendered    = $rum->sum('banner_rendered_count');
            $injectionConfirm  = $rum->sum('injection_confirmed_count');
            $injectionMissing  = $rum->sum('injection_missing_count');
            $jsErrorCount      = $rum->sum('js_error_count');

            // Merge DCL histograms for median
            $mergedDcl = TrafficMetric::emptyHistogram();
            foreach ($rum as $r) {
                $mergedDcl = TrafficMetric::mergeHistograms(
                    $mergedDcl,
                    $r->dcl_histogram ?? TrafficMetric::emptyHistogram()
                );
            }

            $rumByDomain[$domainId] = [
                'pageview_count'             => $totalPageviews,
                'banner_expected_count'      => $bannerExpected,
                'banner_rendered_count'      => $bannerRendered,
                'banner_render_rate'         => $bannerExpected > 0
                    ? round(($bannerRendered / $bannerExpected) * 100, 2)
                    : 0,
                'injection_confirmed_count'  => $injectionConfirm,
                'injection_missing_count'    => $injectionMissing,
                'injection_confirm_rate'     => ($injectionConfirm + $injectionMissing) > 0
                    ? round(($injectionConfirm / ($injectionConfirm + $injectionMissing)) * 100, 2)
                    : null,
                'js_error_count'             => $jsErrorCount,
                // Guard against int64 overflow — cap DCL at 30s, null if unreasonable
                'dcl_median_ms'              => (function () use ($mergedDcl) {
                    $val = TrafficMetric::percentileFromHistogram($mergedDcl, 50);
                    return ($val > 0 && $val <= 30000) ? $val : null;
                })(),
            ];
        }

        // ── Alert counts per domain ───────────────────────────
        $alerts = [];
        foreach ($domainIds as $domainId) {
            $alerts[$domainId] = TrafficAlertState::where('domain_id', $domainId)
                ->whereBetween('created_at', [$from, $to])
                ->count();
        }

        // ── Per-domain KPI bags ───────────────────────────────
        $domains = [];
        foreach ($domainIds as $domainId) {
            $edge = $edgeByDomain[$domainId] ?? $this->emptyEdgeKpis();
            $rum  = $rumByDomain[$domainId] ?? $this->emptyRumKpis();

            $domains[$domainId] = array_merge($edge, $rum, [
                'alert_count' => $alerts[$domainId] ?? 0,
            ]);
        }

        // ── Group summary (averages across domains with traffic) ──
        $activeDomains = collect($domains)->filter(fn($d) => ($d['total_requests'] ?? 0) > 0);

        $summary = [
            'total_requests'       => collect($domains)->sum('total_requests'),
            'domain_count'         => $domainIds->count(),
            'active_domain_count'  => $activeDomains->count(),
            'edge_p95_latency_ms'  => $activeDomains->isNotEmpty()
                ? (int) round($activeDomains->avg('edge_p95_latency_ms'))
                : null,
            'inject_rate'          => $activeDomains->isNotEmpty()
                ? round($activeDomains->avg('inject_rate'), 2)
                : null,
            'banner_render_rate'   => $activeDomains->filter(fn($d) => $d['banner_render_rate'] !== null)->isNotEmpty()
                ? round($activeDomains->filter(fn($d) => $d['banner_render_rate'] !== null)->avg('banner_render_rate'), 2)
                : null,
            'error_rate_5xx'       => $activeDomains->isNotEmpty()
                ? round($activeDomains->avg('error_rate_5xx'), 4)
                : 0,
            'alert_count'          => array_sum($alerts),
        ];

        return [
            'domains' => $domains,
            'summary' => $summary,
            'alerts'  => $alerts,
        ];
    }

    protected function emptyKpiSet(): array
    {
        return array_merge($this->emptyEdgeKpis(), $this->emptyRumKpis(), [
            'alert_count' => 0,
        ]);
    }

    protected function emptyEdgeKpis(): array
    {
        return [
            'total_requests'       => 0,
            'total_5xx'            => 0,
            'error_rate_5xx'       => 0,
            'html_responses'       => 0,
            'inject_attempted'     => 0,
            'inject_succeeded'     => 0,
            'inject_rate'          => null,
            'edge_p50_latency_ms'  => null,
            'edge_p95_latency_ms'  => null,
            'edge_p99_latency_ms'  => null,
            'cache_hits'           => 0,
            'cache_misses'         => 0,
        ];
    }

    protected function emptyRumKpis(): array
    {
        return [
            'pageview_count'             => 0,
            'banner_expected_count'      => 0,
            'banner_rendered_count'      => 0,
            'banner_render_rate'         => null,
            'injection_confirmed_count'  => 0,
            'injection_missing_count'    => 0,
            'injection_confirm_rate'     => null,
            'js_error_count'             => 0,
            'dcl_median_ms'              => null,
        ];
    }
}
