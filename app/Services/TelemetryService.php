<?php

namespace App\Services;

use App\Models\AiSetting;
use App\Models\HealthCheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelemetryService
{
    /**
     * Send a health check result to improve.ypsilon.dev.
     *
     * Called after each health check completes. Uses cache throttle
     * to batch multiple results if they arrive close together.
     * Non-blocking — never throws, never delays the user.
     */
    public static function send(HealthCheckResult $result): void
    {
        try {
            $settings = AiSetting::instance();

            if (!$settings->share_telemetry) {
                return;
            }

            if (empty($settings->telemetry_token)) {
                Log::debug('[Telemetry] No token — register via AI Settings first.');
                return;
            }

            // Throttle: send at most once per 5 minutes
            $lockKey = 'telemetry-send-lock';
            if (Cache::has($lockKey)) {
                Log::debug('[Telemetry] Throttled — will send with next check.');
                return;
            }

            // Take the lock for 5 minutes
            Cache::put($lockKey, true, 300);

            // Collect all unsent results (including this one)
            $unsent = HealthCheckResult::whereNull('telemetry_sent_at')
                ->orderBy('checked_at')
                ->limit(50)
                ->get();

            if ($unsent->isEmpty()) {
                return;
            }

            // Build anonymized payload
            $logs = $unsent->map(fn(HealthCheckResult $r) => self::anonymizeResult($r))->toArray();
            $payload = json_encode(['logs' => $logs]);
            $compressed = gzencode($payload);

            $endpoint = $settings->telemetry_endpoint ?: 'https://improve.ypsilon.dev/api/ingest';

            $response = Http::withToken($settings->telemetry_token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                    'X-YCookies-Version' => config('app.version', '1.0.0'),
                ])
                ->withBody($compressed, 'application/json')
                ->timeout(10)
                ->connectTimeout(5)
                ->post($endpoint);

            if ($response->successful()) {
                $unsent->each(fn($r) => $r->update(['telemetry_sent_at' => now()]));
                Log::info('[Telemetry] Sent ' . $unsent->count() . ' results successfully.');
            } else {
                // Release lock early so next check can retry
                Cache::forget($lockKey);
                Log::warning('[Telemetry] API returned HTTP ' . $response->status());
            }

        } catch (\Throwable $e) {
            // Never block the user — silently fail
            Cache::forget('telemetry-send-lock');
            Log::debug('[Telemetry] Send failed (non-critical): ' . $e->getMessage());
        }
    }

    /**
     * Anonymize a health check result for telemetry.
     */
    protected static function anonymizeResult(HealthCheckResult $result): array
    {
        $checks = $result->checks ?? [];
        $cmsDetected = null;
        $sslDaysLeft = null;
        $cookieCount = ['essential' => 0, 'non_essential' => 0];
        $checkSummary = [];

        foreach ($checks as $check) {
            $checkSummary[] = [
                'name' => $check['name'] ?? 'unknown',
                'status' => $check['status'] ?? 'unknown',
                'severity' => $check['severity'] ?? 'informational',
                'duration_ms' => $check['duration_ms'] ?? null,
            ];

            if (($check['name'] ?? '') === 'cms_detection' && isset($check['evidence'])) {
                $cmsDetected = $check['evidence']['cms'] ?? $check['evidence']['generator'] ?? $check['evidence']['runtime'] ?? null;
            }
            if (($check['name'] ?? '') === 'ssl_validity' && isset($check['evidence'])) {
                $sslDaysLeft = $check['evidence']['days_left'] ?? null;
            }
            if (($check['name'] ?? '') === 'cookie_compliance' && isset($check['evidence'])) {
                $cookieCount = [
                    'essential' => count($check['evidence']['essential'] ?? []),
                    'non_essential' => count($check['evidence']['non_essential'] ?? []),
                ];
            }
        }

        return [
            'domain' => $result->domain_name,
            'timestamp' => $result->checked_at?->toIso8601String(),
            'overall_status' => $result->status,
            'checks_total' => $result->checks_total,
            'checks_passed' => $result->checks_passed,
            'checks_warned' => $result->checks_warned,
            'checks_failed' => $result->checks_failed,
            'duration_ms' => $result->duration_ms,
            'cms_detected' => $cmsDetected,
            'ssl_days_left' => $sslDaysLeft,
            'cookie_count' => $cookieCount,
            'source' => $result->source,
            'ai_summary' => $result->ai_diagnosis['human_message'] ?? null,
            'check_summary' => $checkSummary,
        ];
    }

    /**
     * Send an anonymized daily digest to improve.ypsilon.dev.
     *
     * Gated by share_telemetry — independent of AI enablement (is_active).
     * A user who wants local AI but not telemetry will NOT have digests pushed.
     */
    public static function sendDigest(\App\Models\DailyTrafficReport $report): void
    {
        try {
            $settings = AiSetting::instance();

            if (!$settings->share_telemetry) {
                return;
            }

            if (empty($settings->telemetry_token)) {
                Log::debug('[Telemetry] No token — cannot send digest.');
                return;
            }

            $anonymized = self::anonymizeDigest($report);
            $payload = json_encode(['digest' => $anonymized]);
            $compressed = gzencode($payload);

            $endpoint = $settings->telemetry_endpoint ?: 'https://improve.ypsilon.dev/api/ingest';
            $digestEndpoint = rtrim($endpoint, '/') . '/digests';
            // Handle case where endpoint already ends with /ingest
            if (str_ends_with($endpoint, '/ingest')) {
                $digestEndpoint = substr($endpoint, 0, -7) . '/digests';
            }

            $response = Http::withToken($settings->telemetry_token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                    'X-YCookies-Version' => config('app.version', '1.0.0'),
                ])
                ->withBody($compressed, 'application/json')
                ->timeout(10)
                ->connectTimeout(5)
                ->retry(3, 1000)
                ->post($digestEndpoint);

            if ($response->successful()) {
                Log::info('[Telemetry] Sent daily digest for group ' . $report->group_id);
            } else {
                Log::warning('[Telemetry] Digest push failed: HTTP ' . $response->status());
            }
        } catch (\Throwable $e) {
            Log::debug('[Telemetry] Digest push failed (non-critical): ' . $e->getMessage());
        }
    }

    /**
     * Anonymize a daily traffic report for telemetry.
     * No domain names or group names leave YCookies.
     */
    protected static function anonymizeDigest(\App\Models\DailyTrafficReport $report): array
    {
        $appKey = config('app.key', '');

        return [
            'group_hash'          => hash('sha256', $report->group_id . $appKey),
            'domain_hash'         => $report->domain_id
                ? hash('sha256', $report->domain_id . $appKey)
                : null,
            'report_date'         => $report->report_date->toDateString(),
            'total_requests'      => $report->total_requests,
            'edge_p95_latency_ms' => $report->edge_p95_latency_ms,
            'inject_rate'         => $report->inject_rate,
            'banner_render_rate'  => $report->banner_render_rate,
            'alert_count'         => $report->alert_count,
            'summary_status'      => $report->summary_status,
            'is_group_summary'    => $report->is_group_summary,
        ];
    }

    /**
     * Send a single domain heartbeat to the hub immediately.
     * Useful when a domain's activation status changes.
     */
    public static function syncDomain(\App\Models\Domain $domain): void
    {
        try {
            $settings = AiSetting::instance();

            if (!$settings->share_telemetry || empty($settings->telemetry_token)) {
                return;
            }

            // Build payload
            $payload = [
                'logs' => [
                    [
                        'domain' => $domain->name,
                        'timestamp' => now()->toIso8601String(),
                        'overall_status' => $domain->health_status ?: 'healthy',
                        'source' => 'manual',
                        'is_active' => $domain->is_active,
                        'proxy_enabled' => $domain->proxy_enabled,
                    ]
                ]
            ];

            $endpoint = $settings->telemetry_endpoint ?: 'https://improve.ypsilon.dev/api/ingest';

            $response = Http::withToken($settings->telemetry_token)
                ->withHeaders([
                    'X-YCookies-Version' => config('app.version', '1.0.0'),
                ])
                ->timeout(10)
                ->post($endpoint, $payload);

            if ($response->successful()) {
                Log::info('[Telemetry] Sync for domain ' . $domain->name . ' successful.');
            } else {
                Log::warning('[Telemetry] Domain sync failed for ' . $domain->name . ': HTTP ' . $response->status());
            }

        } catch (\Throwable $e) {
            Log::debug('[Telemetry] Domain sync failed (non-critical): ' . $e->getMessage());
        }
    }
}

