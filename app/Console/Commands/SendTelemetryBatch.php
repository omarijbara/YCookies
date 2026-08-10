<?php

namespace App\Console\Commands;

use App\Models\AiSetting;
use App\Models\HealthCheckResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelemetryBatch extends Command
{
    protected $signature = 'telemetry:send';
    protected $description = 'Send unsent health check results to improve.ypsilon.dev';

    public function handle(): int
    {
        $settings = AiSetting::instance();

        if (!$settings->share_telemetry) {
            $this->info('Telemetry sharing is disabled.');
            return Command::SUCCESS;
        }

        if (empty($settings->telemetry_token)) {
            $this->warn('No telemetry token. Register first via AI Settings.');
            return Command::FAILURE;
        }

        // Collect unsent results (max 50 per batch to keep payloads small)
        $unsent = HealthCheckResult::whereNull('telemetry_sent_at')
            ->orderBy('checked_at')
            ->limit(50)
            ->get();

        if ($unsent->isEmpty()) {
            $this->info('Nothing to send.');
            return Command::SUCCESS;
        }

        $this->info("Preparing batch of {$unsent->count()} health check(s)...");

        // Build anonymized payload
        $logs = $unsent->map(function (HealthCheckResult $result) {
            $checks = $result->checks ?? [];

            // Extract CMS, SSL, cookie data from checks
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

            // AI summary (just the human message, not full diagnosis)
            $aiSummary = null;
            if ($result->ai_diagnosis) {
                $aiSummary = $result->ai_diagnosis['human_message'] ?? null;
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
                'ai_summary' => $aiSummary,
                'check_summary' => $checkSummary,
            ];
        })->toArray();

        $payload = json_encode(['logs' => $logs]);

        // Gzip compress
        $compressed = gzencode($payload);
        $this->info(sprintf(
            'Payload: %s → %s (%.0f%% reduction)',
            $this->formatBytes(strlen($payload)),
            $this->formatBytes(strlen($compressed)),
            (1 - strlen($compressed) / strlen($payload)) * 100
        ));

        // Send to telemetry endpoint
        try {
            $endpoint = $settings->telemetry_endpoint ?: 'https://improve.ypsilon.dev/api/ingest';

            $response = Http::withToken($settings->telemetry_token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                    'X-YCookies-Version' => config('app.version', '1.0.0'),
                ])
                ->withBody($compressed, 'application/json')
                ->timeout(15)
                ->post($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                $stored = $data['stored'] ?? 0;
                $errors = $data['errors'] ?? 0;

                // Mark as sent
                $unsent->each(fn($r) => $r->update(['telemetry_sent_at' => now()]));

                $this->info("✅ Sent {$stored} logs (errors: {$errors})");
                return Command::SUCCESS;
            }

            $this->error("API returned HTTP {$response->status()}: {$response->body()}");
            return Command::FAILURE;

        } catch (\Throwable $e) {
            $this->error('Send failed: ' . $e->getMessage());
            Log::warning('[Telemetry] Batch send failed: ' . $e->getMessage());
            \App\Services\CrashReporter::report($e, [
                'level'  => 'warning',
                'source' => 'telemetry-push',
                'url'    => '/admin/settings',
            ]);
            return Command::FAILURE;
        }
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . 'B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . 'KB';
        return round($bytes / 1048576, 1) . 'MB';
    }
}
