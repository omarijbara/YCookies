<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\HealthCheckResult;
use App\Services\CrashReporter;
use App\Services\HealthCheckerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunHealthCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum retry attempts. */
    public $tries = 2;

    /** Backoff in seconds between retries. */
    public $backoff = 30;

    /** Worker timeout budget for full probe runs. */
    public $timeout = 120;

    public function __construct(
        public Domain $domain,
        public string $source = 'scheduled',
    ) {
        $this->onQueue('health');
    }

    public function handle(HealthCheckerService $checker): void
    {
        Log::info("Running health check for {$this->domain->name} (source: {$this->source})");

        try {
            $result = $checker->run($this->domain);

            $record = HealthCheckResult::create([
                'domain_id' => $this->domain->id,
                'domain_name' => $this->domain->name,
                'source' => $this->source,
                'status' => $result['status'],
                'checks_total' => $result['checks_total'],
                'checks_passed' => $result['checks_passed'],
                'checks_warned' => $result['checks_warned'],
                'checks_failed' => $result['checks_failed'],
                'checks' => $result['checks'],
                'response_times' => $result['response_times'],
                'headers' => $result['headers'],
                'evidence' => $result['evidence'],
                'duration_ms' => $result['duration_ms'],
                'checked_at' => now(),
            ]);

            // Update domain health status + consecutive failure tracking.
            // Use raw DB update to bypass DomainObserver — health status fields
            // don't affect proxy config, so no config_version bump needed.
            if ($result['status'] === 'healthy') {
                DB::table('domains')
                    ->where('id', $this->domain->id)
                    ->update([
                        'health_status' => 'healthy',
                        'last_health_check_at' => now(),
                        'last_health_success_at' => now(),
                        'health_consecutive_failures' => 0,
                    ]);
            } else {
                DB::table('domains')
                    ->where('id', $this->domain->id)
                    ->update([
                        'health_status' => $result['status'],
                        'last_health_check_at' => now(),
                        'health_consecutive_failures' => $this->domain->health_consecutive_failures + 1,
                    ]);
            }

            Log::info("Health check complete for {$this->domain->name}: {$result['status']} ({$result['checks_passed']}/{$result['checks_total']} passed, {$result['duration_ms']}ms)");

            // Sync automated background check with the telemetry hub
            \App\Services\TelemetryService::send($record);

        } catch (\Throwable $e) {
            Log::error("Health check failed for {$this->domain->name}: " . $e->getMessage());

            // Still update domain status on complete failure (raw DB, no observer).
            DB::table('domains')
                ->where('id', $this->domain->id)
                ->update([
                    'health_status' => 'failing',
                    'last_health_check_at' => now(),
                    'health_consecutive_failures' => $this->domain->health_consecutive_failures + 1,
                ]);

            CrashReporter::report($e, [
                'level'   => 'error',
                'source'  => 'health-checker',
                'url'     => '/admin/health-checker',
                'domain'  => $this->domain->name,
            ]);
            throw $e; // Re-throw for retry
        }
    }
}
