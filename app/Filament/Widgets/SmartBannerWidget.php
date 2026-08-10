<?php

namespace App\Filament\Widgets;

use App\Models\TrafficMetric;
use App\Models\TrafficAlertState;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class SmartBannerWidget extends Widget
{
    protected static ?int $sort = 2;
    protected string $view = 'filament.widgets.smart-banner-widget';
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = true;

    public string $message = '';
    public string $severity = 'info'; // info, warning, danger
    public string $icon = 'heroicon-m-information-circle';

    public function mount(): void
    {
        $this->computeBanner();
    }

    protected function computeBanner(): void
    {
        $since = now()->subDay();
        $aggregates = DB::table('traffic_metrics')
            ->where('bucket', '>=', $since)
            ->selectRaw('
                SUM(cache_hits) as cache_hits,
                SUM(cache_misses) as cache_misses,
                SUM(request_count) as request_count,
                SUM(status_5xx) as status_5xx
            ')->first();

        // Compute p95 latency
        $rawMetrics = DB::table('traffic_metrics')
            ->where('bucket', '>=', $since)
            ->pluck('latency_histogram');
        $merged = TrafficMetric::emptyHistogram();
        foreach ($rawMetrics as $h) {
            $parsed = json_decode($h, true) ?? TrafficMetric::emptyHistogram();
            $merged = TrafficMetric::mergeHistograms($merged, $parsed);
        }
        $p95 = TrafficMetric::percentileFromHistogram($merged, 95);

        $cacheTotal = ($aggregates->cache_hits ?? 0) + ($aggregates->cache_misses ?? 0);
        $cacheRate = $cacheTotal > 0 
            ? round(($aggregates->cache_hits / $cacheTotal) * 100, 1) 
            : 0;

        $errorRate = ($aggregates->request_count ?? 0) > 0
            ? round((($aggregates->status_5xx ?? 0) / $aggregates->request_count) * 100, 2)
            : 0;

        $openAlerts = TrafficAlertState::where('state', TrafficAlertState::STATE_OPEN)->count();

        // Priority: latency critical > high error rate > low cache > open alerts
        if ($p95 >= 2000) {
            $this->severity = 'danger';
            $this->icon = 'heroicon-m-exclamation-triangle';
            $this->message = "Edge latency is critical ({$p95}ms p95). Cache hit rate {$cacheRate}% — investigate origin response times.";
        } elseif ($errorRate >= 5) {
            $this->severity = 'danger';
            $this->icon = 'heroicon-m-x-circle';
            $this->message = "5xx error rate is {$errorRate}% — {$aggregates->status_5xx} errors out of {$aggregates->request_count} requests.";
        } elseif ($cacheRate < 50 && $cacheTotal > 100) {
            $this->severity = 'warning';
            $this->icon = 'heroicon-m-bolt';
            $this->message = "Cache hit rate is low ({$cacheRate}%). Consider checking proxy cache configuration.";
        } elseif ($openAlerts > 0) {
            $this->severity = 'warning';
            $this->icon = 'heroicon-m-bell-alert';
            $this->message = "{$openAlerts} open traffic alert(s) require attention.";
        } else {
            $this->severity = 'info';
            $this->icon = 'heroicon-m-check-circle';
            $this->message = "All systems nominal. {$p95}ms p95 latency, {$cacheRate}% cache hit rate.";
        }
    }
}
