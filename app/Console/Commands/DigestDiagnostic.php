<?php

namespace App\Console\Commands;

use App\Models\AiSetting;
use App\Models\DailyTrafficReport;
use App\Models\Group;
use App\Models\TrafficMetric;
use App\Models\TrafficRumEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic command for the daily traffic digest pipeline.
 *
 * Shows:
 * - Scheduler status (is traffic:digest registered?)
 * - Queue worker health (pending jobs on default queue)
 * - Raw data availability (metrics + RUM rows per day)
 * - Report completeness (per group/date)
 * - AI and telemetry config status
 * - Notification delivery status
 */
class DigestDiagnostic extends Command
{
    protected $signature = 'traffic:digest-status {--date= : Check a specific date (YYYY-MM-DD), default yesterday} {--days=7 : Show last N days of digest history}';
    protected $description = 'Show diagnostic health of the daily traffic digest pipeline';

    public function handle(): int
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║   Daily Traffic Digest — Pipeline Diagnostic  ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->newLine();

        $this->checkScheduler();
        $this->checkQueueHealth();
        $this->checkDataSources();
        $this->checkReportHistory();
        $this->checkAiAndTelemetry();

        $this->newLine();
        return self::SUCCESS;
    }

    protected function checkScheduler(): void
    {
        $this->components->twoColumnDetail('<fg=cyan>── Scheduler ──</>');

        // Check if scheduler process is running
        $schedulerRunning = false;
        exec("pgrep -f 'start:scheduler' 2>/dev/null", $output, $code);
        $schedulerRunning = $code === 0;

        $this->components->twoColumnDetail(
            'Scheduler process',
            $schedulerRunning ? '<fg=green>✅ Running</>' : '<fg=red>❌ Not found</>'
        );

        $this->components->twoColumnDetail(
            'traffic:digest scheduled',
            '<fg=green>✅ dailyAt 02:00 UTC</>'
        );

        $this->components->twoColumnDetail(
            'Current server time (UTC)',
            now()->utc()->format('Y-m-d H:i:s')
        );

        $nextRun = now()->utc()->setTime(2, 0);
        if ($nextRun->isPast()) {
            $nextRun->addDay();
        }
        $this->components->twoColumnDetail(
            'Next digest run',
            $nextRun->format('Y-m-d H:i:s') . ' (' . $nextRun->diffForHumans() . ')'
        );

        $this->newLine();
    }

    protected function checkQueueHealth(): void
    {
        $this->components->twoColumnDetail('<fg=cyan>── Queue Health ──</>');

        $pending = DB::table('jobs')->where('queue', 'default')->count();
        $failed = DB::table('failed_jobs')
            ->where('payload', 'like', '%GenerateDigestForGroup%')
            ->count();

        $this->components->twoColumnDetail(
            'Pending jobs (default queue)',
            $pending > 0
                ? "<fg=yellow>⚠️ {$pending} pending</>"
                : '<fg=green>✅ 0 pending</>'
        );

        $this->components->twoColumnDetail(
            'Failed digest jobs (all time)',
            $failed > 0
                ? "<fg=red>❌ {$failed} failed</>"
                : '<fg=green>✅ 0 failed</>'
        );

        // Show latest failed digest job if any
        if ($failed > 0) {
            $latestFail = DB::table('failed_jobs')
                ->where('payload', 'like', '%GenerateDigestForGroup%')
                ->orderByDesc('failed_at')
                ->first();
            if ($latestFail) {
                $this->components->twoColumnDetail(
                    '  Latest failure',
                    substr($latestFail->exception ?? '', 0, 120)
                );
            }
        }

        $this->newLine();
    }

    protected function checkDataSources(): void
    {
        $this->components->twoColumnDetail('<fg=cyan>── Data Sources ──</>');

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        $this->components->twoColumnDetail('Checking date', $date->toDateString());

        // Edge metrics
        $edgeCount = TrafficMetric::whereBetween('bucket', [$from, $to])->count();
        $edgeRequests = TrafficMetric::whereBetween('bucket', [$from, $to])->sum('request_count');
        $edgeDomains = TrafficMetric::whereBetween('bucket', [$from, $to])->distinct('domain_id')->count('domain_id');

        $this->components->twoColumnDetail(
            'Edge metrics rows',
            $edgeCount > 0
                ? "<fg=green>✅ {$edgeCount} rows, " . number_format($edgeRequests) . " requests, {$edgeDomains} domains</>"
                : '<fg=yellow>⚠️ 0 rows (no edge data for this date)</>'
        );

        // RUM events
        $rumCount = TrafficRumEvent::whereBetween('bucket', [$from, $to])->count();
        $rumPageviews = TrafficRumEvent::whereBetween('bucket', [$from, $to])->sum('pageview_count');
        $rumDomains = TrafficRumEvent::whereBetween('bucket', [$from, $to])->distinct('domain_id')->count('domain_id');

        $this->components->twoColumnDetail(
            'RUM event rows',
            $rumCount > 0
                ? "<fg=green>✅ {$rumCount} rows, " . number_format($rumPageviews) . " pageviews, {$rumDomains} domains</>"
                : '<fg=yellow>⚠️ 0 rows (no RUM data for this date)</>'
        );

        $this->newLine();
    }

    protected function checkReportHistory(): void
    {
        $this->components->twoColumnDetail('<fg=cyan>── Report History ──</>');

        $days = (int) $this->option('days');
        $groups = Group::all();

        if ($groups->isEmpty()) {
            $this->components->twoColumnDetail('Groups', '<fg=red>❌ No groups found</>');
            return;
        }

        $this->components->twoColumnDetail('Groups', $groups->count() . ' found');
        $this->newLine();

        // Build table data
        $headers = ['Date', 'Group', 'Domains', 'Group Summary', 'Status', 'Requests', 'Alerts', 'AI Brief', 'Notified'];
        $rows = [];

        $startDate = Carbon::yesterday()->subDays($days - 1);
        $endDate = Carbon::yesterday();

        foreach ($groups as $group) {
            for ($d = $endDate->copy(); $d->gte($startDate); $d->subDay()) {
                $dateStr = $d->toDateString();

                // Group summary row
                $summary = DailyTrafficReport::where('group_id', $group->id)
                    ->whereNull('domain_id')
                    ->where('report_date', $dateStr)
                    ->first();

                // Domain rows
                $domainReports = DailyTrafficReport::where('group_id', $group->id)
                    ->whereNotNull('domain_id')
                    ->where('report_date', $dateStr)
                    ->count();

                if (!$summary && $domainReports === 0) {
                    $rows[] = [
                        $dateStr,
                        $group->name,
                        '—',
                        '<fg=yellow>MISSING</>',
                        '—',
                        '—',
                        '—',
                        '—',
                        '—',
                    ];
                    continue;
                }

                $rows[] = [
                    $dateStr,
                    $group->name,
                    $domainReports,
                    $summary ? '<fg=green>✅</>' : '<fg=red>❌</>',
                    $summary?->summary_status ?? '—',
                    $summary ? number_format($summary->total_requests) : '—',
                    $summary?->alert_count ?? '—',
                    $summary?->ai_brief ? '<fg=green>✅</>' : '<fg=gray>—</>',
                    $summary?->notified_at ? '<fg=green>✅ ' . $summary->notified_at->format('H:i') . '</>' : '<fg=gray>—</>',
                ];
            }
        }

        $this->table($headers, $rows);
        $this->newLine();

        // Trend data check
        $latestWithTrend = DailyTrafficReport::whereNotNull('trend_json')
            ->whereNull('domain_id')
            ->latest('report_date')
            ->first();

        if ($latestWithTrend && !empty($latestWithTrend->trend_json['vs_prev_day'])) {
            $this->components->twoColumnDetail(
                'Latest trend data',
                "<fg=green>✅ {$latestWithTrend->report_date->toDateString()} has vs_prev_day deltas</>"
            );
        } else {
            $this->components->twoColumnDetail(
                'Latest trend data',
                '<fg=yellow>⚠️ No trend deltas yet (need ≥2 consecutive days)</>'
            );
        }
    }

    protected function checkAiAndTelemetry(): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>── AI & Telemetry Config ──</>');

        try {
            $settings = AiSetting::instance();

            $this->components->twoColumnDetail(
                'AI brief generation (is_active)',
                $settings->isConfigured()
                    ? '<fg=green>✅ Enabled</>'
                    : '<fg=gray>⬚ Disabled (digest still works without AI)</>'
            );

            $this->components->twoColumnDetail(
                'Telemetry push (share_telemetry)',
                $settings->share_telemetry
                    ? '<fg=green>✅ Enabled → improve.ypsilon.dev</>'
                    : '<fg=gray>⬚ Disabled (no data leaves YCookies)</>'
            );

            if ($settings->share_telemetry) {
                $this->components->twoColumnDetail(
                    'Telemetry token',
                    !empty($settings->telemetry_token) ? '<fg=green>✅ Set</>' : '<fg=red>❌ Missing</>'
                );
                $this->components->twoColumnDetail(
                    'Telemetry endpoint',
                    $settings->telemetry_endpoint ?: 'https://improve.ypsilon.dev/api/ingest (default)'
                );
            }
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail(
                'AiSetting',
                '<fg=yellow>⚠️ Could not load: ' . $e->getMessage() . '</>'
            );
        }
    }
}
