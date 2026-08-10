<?php

namespace App\Filament\Resources\Domains\Widgets;

use App\Models\TrafficMetric;
use App\Models\HealthCheckResult;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use SaKanjo\EasyMetrics\Metrics\Trend;

class DomainMetricsWidget extends BaseWidget
{
    public ?Model $record = null;

    protected function getStats(): array
    {
        if (!$this->record) {
            return [];
        }

        // 1. Traffic last 7 days
        $trafficTrend = Trend::make(TrafficMetric::query()->where('domain_id', $this->record->id))
            ->dateColumn('bucket')
            ->range(7)
            ->sumByDays('request_count');

        $totalRequests = array_sum($trafficTrend->getData());

        // 2. Health checks last 24h
        $recentChecks = HealthCheckResult::where('domain_id', $this->record->id)
            ->orderByDesc('id')
            ->take(10)
            ->get();
        // Since Health checks don't have a trend out of the box, we just calculate success rate
        $fails = $recentChecks->where('status', 'failing')->count();
        $healthStr = $fails == 0 ? '100% Healthy' : 'Failing checks detected';
        $healthColor = $fails == 0 ? 'success' : 'danger';

        // 3. Cookie Bar
        $cookieBar = $this->record->cookieBar;
        $themeName = $cookieBar ? $cookieBar->name : 'None Assigned';

        return [
            Stat::make('Traffic (7 Days)', number_format($totalRequests) . ' requests')
                ->description('Edge requests served')
                ->chart($trafficTrend->getData())
                ->color('primary'),

            Stat::make('Recent Health', $healthStr)
                ->description('Based on last 10 checks')
                ->descriptionIcon($fails == 0 ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle')
                ->color($healthColor),

            Stat::make('Assigned Theme', $themeName)
                ->description('Current active UI design')
                ->descriptionIcon('heroicon-m-swatch')
                ->color('info'),
        ];
    }
}
