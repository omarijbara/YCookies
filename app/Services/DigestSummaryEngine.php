<?php

namespace App\Services;

use App\Models\DailyTrafficReport;

/**
 * DigestSummaryEngine — deterministic, rule-based classification and recommendations.
 *
 * This engine produces useful summaries WITHOUT AI. The ai_brief field is additive;
 * summary_status + recommendations_json must always work standalone.
 */
class DigestSummaryEngine
{
    // ── Status classification thresholds ─────────────────────

    const CRITICAL_P95_MS       = 5000;
    const CRITICAL_5XX_RATE     = 0.10;  // 10%
    const CRITICAL_INJECT_RATE  = 90.0;  // below this = critical

    const DEGRADED_P95_MS       = 3000;
    const DEGRADED_5XX_RATE     = 0.05;  // 5%

    /**
     * Classify a KPI set into a status label.
     *
     * Priority: critical > degraded > stable > no_data
     * Works on both domain-level and group-level KPIs.
     */
    public static function classifyStatus(array $kpis): string
    {
        $totalRequests = $kpis['total_requests'] ?? 0;
        if ($totalRequests === 0) {
            return DailyTrafficReport::STATUS_NO_DATA;
        }

        $p95        = $kpis['edge_p95_latency_ms'] ?? 0;
        $injectRate = $kpis['inject_rate'] ?? 100.0;
        $errorRate  = $kpis['error_rate_5xx'] ?? 0;

        // Critical checks
        if ($p95 > self::CRITICAL_P95_MS) return DailyTrafficReport::STATUS_CRITICAL;
        if ($errorRate > self::CRITICAL_5XX_RATE) return DailyTrafficReport::STATUS_CRITICAL;
        if ($injectRate < self::CRITICAL_INJECT_RATE && $totalRequests > 100) return DailyTrafficReport::STATUS_CRITICAL;

        // Degraded checks
        $alertCount = $kpis['alert_count'] ?? 0;
        if ($p95 > self::DEGRADED_P95_MS) return DailyTrafficReport::STATUS_DEGRADED;
        if ($errorRate > self::DEGRADED_5XX_RATE) return DailyTrafficReport::STATUS_DEGRADED;
        if ($alertCount > 0) return DailyTrafficReport::STATUS_DEGRADED;

        return DailyTrafficReport::STATUS_STABLE;
    }

    /**
     * Generate rule-based, plain-language recommendations.
     *
     * Each recommendation is a short actionable string.
     * Uses current KPIs + trend deltas to produce context-aware advice.
     */
    public static function generateRecommendations(array $kpis, ?array $trends = null): array
    {
        $recs = [];
        $totalRequests = $kpis['total_requests'] ?? 0;

        if ($totalRequests === 0) {
            $recs[] = 'No traffic recorded yesterday — verify proxy is routing requests for this domain.';
            return $recs;
        }

        $p95        = $kpis['edge_p95_latency_ms'] ?? 0;
        $injectRate = $kpis['inject_rate'] ?? 100.0;
        $bannerRate = $kpis['banner_render_rate'] ?? null;
        $alertCount = $kpis['alert_count'] ?? 0;
        $errorRate  = $kpis['error_rate_5xx'] ?? 0;

        // ── Latency recommendations ─────────────────────────
        if ($p95 > self::CRITICAL_P95_MS) {
            $recs[] = "p95 latency is {$p95}ms — critical. Check upstream origin response times and proxy resource usage.";
        } elseif ($p95 > self::DEGRADED_P95_MS) {
            $recs[] = "p95 latency is {$p95}ms — elevated. Monitor upstream origin for slowdowns.";
        }

        // Trend-aware latency advice
        $p95DeltaPct = $trends['vs_prev_day']['p95_delta_pct'] ?? null;
        if ($p95DeltaPct !== null && $p95DeltaPct > 20) {
            $recs[] = sprintf(
                'p95 latency increased %.0f%% vs yesterday — investigate recent origin changes.',
                $p95DeltaPct
            );
        } elseif ($p95DeltaPct !== null && $p95DeltaPct < -20) {
            $recs[] = sprintf(
                'p95 latency improved %.0f%% vs yesterday — good trend.',
                abs($p95DeltaPct)
            );
        }

        // ── Error rate recommendations ──────────────────────
        if ($errorRate > self::CRITICAL_5XX_RATE) {
            $pct = round($errorRate * 100, 1);
            $recs[] = "5xx error rate is {$pct}% — investigate origin errors immediately.";
        } elseif ($errorRate > self::DEGRADED_5XX_RATE) {
            $pct = round($errorRate * 100, 1);
            $recs[] = "5xx error rate is {$pct}% — elevated. Check origin health.";
        }

        // ── Injection recommendations ───────────────────────
        if ($injectRate < self::CRITICAL_INJECT_RATE && $totalRequests > 100) {
            $recs[] = sprintf(
                'Script injection rate is %.1f%% — below threshold. Verify HTML transform pipeline.',
                $injectRate
            );
        }

        // ── Banner render recommendations ───────────────────
        if ($bannerRate !== null && $bannerRate < 5.0 && $injectRate > 95.0) {
            $recs[] = 'Banner render <5% with injection confirmed — possible competing CMP or banner suppression.';
        }

        // ── Alert recommendations ───────────────────────────
        if ($alertCount > 0) {
            $alertDelta = $trends['vs_prev_day']['alert_count_delta'] ?? null;
            if ($alertDelta !== null && $alertDelta > 0) {
                $recs[] = "{$alertCount} active alerts (+{$alertDelta} vs yesterday) — review alert timeline.";
            } else {
                $recs[] = "{$alertCount} active alert(s) — review alert timeline for details.";
            }
        }

        // ── Stable domain ───────────────────────────────────
        if (empty($recs)) {
            // Check 7-day stability
            $p957dDelta = $trends['vs_7d_avg']['p95_delta_pct'] ?? null;
            if ($p957dDelta !== null && abs($p957dDelta) < 10) {
                $recs[] = 'All metrics stable — no action needed.';
            } else {
                $recs[] = 'All metrics within thresholds — no action needed.';
            }
        }

        return $recs;
    }

    /**
     * Enrich recommendations with health check data (CMP, scripts, iframes).
     *
     * Accepts the 'evidence' JSON from a HealthCheckResult and produces
     * actionable recommendations based on:
     * - Competing CMP presence
     * - High third-party script load
     * - Notable iframe sources
     *
     * @param  array|null  $evidence  The evidence column from HealthCheckResult
     * @return array  Additional recommendation strings to append
     */
    public static function enrichRecommendationsFromHealthCheck(?array $evidence): array
    {
        if (empty($evidence)) {
            return [];
        }

        $recs = [];

        // ── CMP competition ─────────────────────────────────
        $cmpEvidence = $evidence['cmp_detection'] ?? null;
        if ($cmpEvidence) {
            $competingCmp = $cmpEvidence['competing_cmp'] ?? false;
            $detectedCmps = $cmpEvidence['detected_cmps'] ?? [];
            $ycookiesFound = $cmpEvidence['ycookies_found'] ?? false;

            if ($competingCmp && $ycookiesFound) {
                $otherNames = array_filter(array_keys($detectedCmps), fn ($n) => $n !== 'YCookies');
                $names = implode(', ', $otherNames);
                $recs[] = "Competing CMP detected ({$names}) — may suppress YCookies banner or cause double-consent prompts.";
            } elseif ($competingCmp && !$ycookiesFound) {
                $otherNames = array_keys($detectedCmps);
                $names = implode(', ', $otherNames);
                $recs[] = "CMP detected ({$names}) but YCookies not found — likely suppressed or not injected.";
            }
        }

        // ── Third-party script load ─────────────────────────
        $scriptEvidence = $evidence['third_party_scripts'] ?? null;
        if ($scriptEvidence) {
            $thirdParty = $scriptEvidence['third_party'] ?? 0;
            $byCategory = $scriptEvidence['by_category'] ?? [];

            if ($thirdParty > 30) {
                $recs[] = "High third-party script load ({$thirdParty} scripts) — may affect page performance and consent coverage.";
            }

            $adCount = $byCategory['advertising'] ?? 0;
            if ($adCount > 5) {
                $recs[] = "{$adCount} advertising scripts detected — verify consent is applied before ad script execution.";
            }
        }

        // ── Iframe consent gaps ─────────────────────────────
        $iframeEvidence = $evidence['iframe_inventory'] ?? null;
        if ($iframeEvidence) {
            $notable = $iframeEvidence['notable'] ?? [];
            $total = $iframeEvidence['total'] ?? 0;

            // Flag ad-network iframes specifically
            $adIframes = array_filter($notable, fn ($cat) => $cat === 'advertising');
            if (!empty($adIframes)) {
                $adDomains = implode(', ', array_keys($adIframes));
                $recs[] = "Ad network iframes detected ({$adDomains}) — verify consent coverage for embedded ads.";
            }

            // Flag social/video iframes
            $socialIframes = array_filter($notable, fn ($cat) => in_array($cat, ['social', 'video']));
            if (count($socialIframes) >= 3) {
                $recs[] = "{$total} iframes including " . count($socialIframes) . " social/video embeds — ensure consent is handled for embedded content.";
            }
        }

        return $recs;
    }
}

