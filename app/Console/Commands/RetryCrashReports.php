<?php

namespace App\Console\Commands;

use App\Jobs\ForwardErrorsToImprove;
use App\Models\CrashReport;
use Illuminate\Console\Command;

/**
 * Retry pushing unsent crash reports to Improve Ypsilon.
 *
 * Designed to run on schedule (e.g. every 5 minutes).
 * Picks up errors that failed to push or were created while
 * the queue was down.
 */
class RetryCrashReports extends Command
{
    protected $signature = 'crash-reporter:retry {--limit=50 : Max errors per batch}';
    protected $description = 'Retry pushing unsent crash reports to Improve Ypsilon';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $unsent = CrashReport::unsent()
            ->orderBy('last_seen_at')
            ->limit($limit)
            ->get();

        if ($unsent->isEmpty()) {
            $this->info('✅ All crash reports are synced — nothing to retry.');
            return Command::SUCCESS;
        }

        $this->info("📤 Found {$unsent->count()} unsent crash report(s). Dispatching...");

        // Batch them into a single job
        $errors = $unsent->map(fn (CrashReport $r) => [
            'level'            => $r->level,
            'source'           => $r->source,
            'message'          => $r->message,
            'stack_trace'      => $r->stack_trace,
            'context'          => $r->context,
            'occurred_at'      => $r->last_seen_at->toIso8601String(),
            'fingerprint'      => $r->fingerprint,
            'occurrence_count' => $r->occurrence_count,
        ])->toArray();

        $ids = $unsent->pluck('id')->toArray();

        ForwardErrorsToImprove::dispatch($errors, $ids);

        $this->info("✅ Dispatched {$unsent->count()} error(s) to queue.");
        return Command::SUCCESS;
    }
}
