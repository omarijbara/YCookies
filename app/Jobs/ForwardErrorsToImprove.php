<?php

namespace App\Jobs;

use App\Models\CrashReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ForwardErrorsToImprove implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 60, 300];

    protected $errors;
    protected $crashReportIds;

    /**
     * @param array $errors         Error payloads to forward
     * @param array $crashReportIds Local CrashReport IDs to mark as sent on success
     */
    public function __construct(array $errors, array $crashReportIds = [])
    {
        $this->errors = $errors;
        $this->crashReportIds = $crashReportIds;
    }

    public function handle()
    {
        $settings = \App\Models\AiSetting::instance();

        if (!$settings->share_telemetry || empty($settings->telemetry_token)) {
            Log::debug('[Error Bridge] Skipping forward - Telemetry disabled or missing token');
            // Still mark as "sent" to avoid infinite retry — telemetry is intentionally off
            $this->markAsSent();
            return;
        }

        $url = $settings->telemetry_endpoint ?: 'https://improve.ypsilon.dev/api/ingest';
        $key = $settings->telemetry_token;

        $hubUrl = str_ends_with($url, '/ingest') 
            ? substr($url, 0, -7) . '/errors'
            : rtrim($url, '/') . '/errors';
        
        $response = Http::withHeaders([
            'X-Improve-Key' => $key,
            'Accept' => 'application/json',
            'X-YCookies-Version' => config('app.version', '1.0.0'),
        ])->timeout(5)->connectTimeout(2)->post($hubUrl, [
            'instance_id' => config('app.url'),
            'errors' => $this->errors,
            'batch_at' => now()->toIso8601String(),
        ]);

        if ($response->failed()) {
            throw new \Exception("Failed to forward errors to Improve Hub: " . $response->status());
        }

        // ✅ Success — mark local crash reports as sent
        $this->markAsSent();
    }

    /** Mark local CrashReport records as successfully pushed. */
    protected function markAsSent(): void
    {
        if (!empty($this->crashReportIds)) {
            CrashReport::whereIn('id', $this->crashReportIds)
                ->whereNull('telemetry_sent_at')
                ->update(['telemetry_sent_at' => now()]);
        }
    }
}
