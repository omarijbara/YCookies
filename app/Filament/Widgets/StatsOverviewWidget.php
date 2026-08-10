<?php

namespace App\Filament\Widgets;

use App\Models\Domain;
use App\Models\TrafficMetric;
use App\Models\TrafficAlertState;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use SaKanjo\EasyMetrics\Metrics\Trend;
use SaKanjo\EasyMetrics\Metrics\Value;

class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        // ── Total Domains — 7-day trend from real data ──────────
        $domainTrend = Trend::make(Domain::query())
            ->range(7)
            ->countByDays();

        $totalDomains = Domain::count();

        // ── Active Proxies — domains with traffic in last 24h ───
        $activeProxyIds = TrafficMetric::where('bucket', '>=', now()->subDay())
            ->distinct('domain_id')
            ->pluck('domain_id');
        $activeProxies = $activeProxyIds->count();

        // Build a simple 7-point sparkline from daily active domain counts
        $proxySparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = now()->subDays($i)->startOfDay();
            $dayEnd = now()->subDays($i)->endOfDay();
            $proxySparkline[] = TrafficMetric::whereBetween('bucket', [$dayStart, $dayEnd])
                ->distinct('domain_id')
                ->count('domain_id');
        }

        // ── Total Requests — 24h with 7-day sparkline ───────────
        $totalRequests24h = TrafficMetric::where('bucket', '>=', now()->subDay())
            ->sum('request_count');

        $requestTrend = Trend::make(TrafficMetric::query())
            ->dateColumn('bucket')
            ->range(7)
            ->sumByDays('request_count');

        // ── Open Alerts ─────────────────────────────────────────
        $openAlerts = TrafficAlertState::whereIn('state', [
            TrafficAlertState::STATE_OPEN,
            TrafficAlertState::STATE_SUPPRESSED,
        ])->count();

        // ── Scan Summary ────────────────────────────────────────
        $latestScanIds = \App\Models\ScanResult::selectRaw('MAX(id) as id')
            ->groupBy('domain_id')
            ->pluck('id');

        $totalUnknownScripts = \App\Models\ScanResult::whereIn('id', $latestScanIds)
            ->sum('unknown_count');

        return [
            Stat::make('Total Domains', $totalDomains)
                ->description('Active tenants mapped')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('info')
                ->chart($domainTrend->getData()),

            Stat::make('Active Proxies', $activeProxies)
                ->extraAttributes(['title' => 'Domains currently routing traffic through the proxy layer.'])
                ->description('Domains with traffic (24h)')
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('success')
                ->chart($proxySparkline),

            Stat::make('Total Requests (24h)', number_format($totalRequests24h))
                ->extraAttributes(['title' => 'Total requests handled by the proxy in the last 24 hours.'])
                ->description('Edge proxy throughput')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary')
                ->chart($requestTrend->getData()),

            Stat::make('Open Alerts', $openAlerts)
                ->description($openAlerts > 0 ? 'Needs attention' : 'All clear')
                ->descriptionIcon($openAlerts > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($openAlerts > 0 ? 'danger' : 'success'),

            Stat::make('Scan Summary', number_format($totalUnknownScripts))
                ->extraAttributes(['title' => 'Third-party scripts detected on your sites that aren\'t categorized yet.'])
                ->description('Pending unknown scripts')
                ->descriptionIcon('heroicon-m-document-magnifying-glass')
                ->color($totalUnknownScripts > 0 ? 'warning' : 'success'),
        ];
    }
}
