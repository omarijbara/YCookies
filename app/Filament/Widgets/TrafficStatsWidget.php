<?php

namespace App\Filament\Widgets;

use App\Models\TrafficMetric;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use SaKanjo\EasyMetrics\Metrics\Trend;

/**
 * Traffic KPI widget — edge latency, inject rate, cache rate, error rate.
 * Uses real TrafficMetric data with easy-metrics for sparkline trends.
 */
class TrafficStatsWidget extends BaseStatsOverviewWidget
{
    protected static ?int $sort = 5;
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $since = now()->subDay();
        // Use DB builder to prevent massive memory hydration from Eloquent
        $aggregates = \Illuminate\Support\Facades\DB::table('traffic_metrics')
            ->where('bucket', '>=', $since)
            ->selectRaw('
                SUM(inject_attempted) as inject_attempted,
                SUM(inject_succeeded) as inject_succeeded,
                SUM(cache_hits) as cache_hits,
                SUM(cache_misses) as cache_misses,
                SUM(request_count) as request_count,
                SUM(status_5xx) as status_5xx
            ')->first();

        // Pluck only histograms and buckets for purely functional calculations
        $rawMetrics = \Illuminate\Support\Facades\DB::table('traffic_metrics')
            ->where('bucket', '>=', $since)
            ->select('bucket', 'latency_histogram', 'request_count', 'status_5xx')
            ->get();

        if ($rawMetrics->isEmpty()) {
            return [
                Stat::make('Edge p95 Latency', 'N/A')
                    ->description('No traffic data')
                    ->color('gray'),
                Stat::make('Inject Success Rate', 'N/A')
                    ->description('No traffic data')
                    ->color('gray'),
                Stat::make('Cache Hit Rate', 'N/A')
                    ->description('No traffic data')
                    ->color('gray'),
                Stat::make('5xx Error Rate', 'N/A')
                    ->description('No traffic data')
                    ->color('gray'),
            ];
        }

        // ── Edge p95 Latency & Error Sparkline ────────────────────────
        $mergedLatency = TrafficMetric::emptyHistogram();
        
        // Group raw metrics into hour buckets dynamically
        $hourlyHistograms = array_fill(0, 24, null);
        $hourlyRequests = array_fill(0, 24, 0);
        $hourly5xx = array_fill(0, 24, 0);

        foreach ($rawMetrics as $m) {
            $parsedHistogram = json_decode($m->latency_histogram, true) ?? TrafficMetric::emptyHistogram();
            $mergedLatency = TrafficMetric::mergeHistograms($mergedLatency, $parsedHistogram);
            
            // Map the bucket timestamp to one of the 24 hour slots
            $diffHours = now()->diffInHours(\Illuminate\Support\Carbon::parse($m->bucket));
            if ($diffHours >= 0 && $diffHours < 24) {
                // 0 index is the current hour, 23 is the oldest hour
                if ($hourlyHistograms[$diffHours] === null) {
                    $hourlyHistograms[$diffHours] = TrafficMetric::emptyHistogram();
                }
                $hourlyHistograms[$diffHours] = TrafficMetric::mergeHistograms($hourlyHistograms[$diffHours], $parsedHistogram);
                $hourlyRequests[$diffHours] += $m->request_count;
                $hourly5xx[$diffHours] += $m->status_5xx;
            }
        }
        $p95 = TrafficMetric::percentileFromHistogram($mergedLatency, 95);

        // Hourly p95 sparkline (last 24h, oldest to newest -> 23 down to 0)
        $latencySparkline = [];
        $errorSparkline = [];
        for ($i = 23; $i >= 0; $i--) {
            $h = $hourlyHistograms[$i] ?? TrafficMetric::emptyHistogram();
            $latencySparkline[] = TrafficMetric::percentileFromHistogram($h, 95);
            
            $reqs = $hourlyRequests[$i];
            $errs = $hourly5xx[$i];
            $errorSparkline[] = $reqs > 0 ? round(($errs / $reqs) * 100, 1) : 0;
        }

        $latencyColor = $p95 < 500 ? 'success' : ($p95 < 2000 ? 'warning' : 'danger');

        // ── Inject Success Rate ─────────────────────────────
        $injectAttempted = $aggregates->inject_attempted ?? 0;
        $injectSucceeded = $aggregates->inject_succeeded ?? 0;
        $injectRate = $injectAttempted > 0
            ? round(($injectSucceeded / $injectAttempted) * 100, 1)
            : 0;

        $injectColor = $injectRate >= 98 ? 'success' : ($injectRate >= 90 ? 'warning' : 'danger');

        // ── Cache Hit Rate ──────────────────────────────────
        $cacheHits = $aggregates->cache_hits ?? 0;
        $cacheMisses = $aggregates->cache_misses ?? 0;
        $cacheTotal = $cacheHits + $cacheMisses;
        $cacheRate = $cacheTotal > 0
            ? round(($cacheHits / $cacheTotal) * 100, 1)
            : 0;

        $cacheColor = $cacheRate >= 80 ? 'success' : ($cacheRate >= 50 ? 'warning' : 'danger');

        // ── 5xx Error Rate ──────────────────────────────────
        $totalRequests = $aggregates->request_count ?? 0;
        $total5xx = $aggregates->status_5xx ?? 0;
        $errorRate = $totalRequests > 0
            ? round(($total5xx / $totalRequests) * 100, 2)
            : 0;

        $errorColor = $errorRate < 1 ? 'success' : ($errorRate < 5 ? 'warning' : 'danger');

        return [
            Stat::make('Edge p95 Latency', "{$p95}ms")
                ->extraAttributes(['title' => '95% of requests were faster than this. Lower is better.'])
                ->description($p95 < 500 ? 'Healthy' : ($p95 < 2000 ? 'Elevated' : 'Critical'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($latencyColor)
                ->chart($latencySparkline),

            Stat::make('Inject Success Rate', "{$injectRate}%")
                ->extraAttributes(['title' => 'How often the consent banner was successfully added to pages.'])
                ->description("{$injectSucceeded}/{$injectAttempted} injections")
                ->descriptionIcon('heroicon-m-code-bracket')
                ->color($injectColor),

            Stat::make('Cache Hit Rate', "{$cacheRate}%")
                ->extraAttributes(['title' => 'How often pages were served from cache instead of the origin server.'])
                ->description("{$cacheHits} hits / {$cacheMisses} misses")
                ->descriptionIcon('heroicon-m-bolt')
                ->color($cacheColor),

            Stat::make('5xx Error Rate', "{$errorRate}%")
                ->extraAttributes(['title' => 'Percentage of requests that failed due to server errors.'])
                ->description("{$total5xx} errors of {$totalRequests} requests")
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color($errorColor)
                ->chart($errorSparkline),
        ];
    }
}
