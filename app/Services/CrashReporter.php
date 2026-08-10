<?php

namespace App\Services;

use App\Jobs\ForwardErrorsToImprove;
use App\Models\CrashReport;
use Throwable;
use Illuminate\Support\Facades\Log;

class CrashReporter
{
    /**
     * Report an exception — stores locally first, then queues telemetry push.
     * Non-blocking, fails gracefully.
     */
    public static function report(Throwable $exception, array $context = [])
    {
        try {
            $message = $exception->getMessage();
            $file = $exception->getFile();
            $line = $exception->getLine();
            $fingerprint = hash('sha256', "{$message}:{$file}:{$line}");

            $fullContext = array_merge([
                'file'        => $file,
                'line'        => $line,
                'url'         => request()->fullUrl(),
                'method'      => request()->method(),
                'user_id'     => auth()->id(),
                'ip'          => request()->ip(),
                'php_version' => PHP_VERSION,
            ], $context);

            // ── Step 1: Store locally (upsert by fingerprint) ──
            $report = CrashReport::where('fingerprint', $fingerprint)->first();

            if ($report) {
                $report->update([
                    'occurrence_count' => $report->occurrence_count + 1,
                    'last_seen_at'     => now(),
                    'stack_trace'      => $exception->getTraceAsString(),
                    'context'          => $fullContext,
                    'telemetry_sent_at' => null, // mark as unsent so it gets re-pushed
                ]);
            } else {
                $report = CrashReport::create([
                    'source'           => $context['source'] ?? 'laravel-ycookies',
                    'level'            => $context['level'] ?? 'error',
                    'message'          => $message,
                    'stack_trace'      => $exception->getTraceAsString(),
                    'context'          => $fullContext,
                    'fingerprint'      => $fingerprint,
                    'occurrence_count' => 1,
                    'first_seen_at'    => now(),
                    'last_seen_at'     => now(),
                ]);
            }

            // ── Step 2: Queue telemetry push (best-effort) ──
            $error = [
                'level'            => $report->level,
                'source'           => $report->source,
                'message'          => $report->message,
                'stack_trace'      => $report->stack_trace,
                'context'          => $report->context,
                'occurred_at'      => $report->last_seen_at->toIso8601String(),
                'fingerprint'      => $report->fingerprint,
                'occurrence_count' => $report->occurrence_count,
            ];

            ForwardErrorsToImprove::dispatch([$error], [$report->id]);

        } catch (Throwable $e) {
            // Never let the reporter itself crash the app
            Log::error("[CrashReporter] Secondary failure: " . $e->getMessage());
        }
    }
}
