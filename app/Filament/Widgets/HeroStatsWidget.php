<?php

namespace App\Filament\Widgets;

use App\Models\Domain;
use App\Models\TrafficMetric;
use App\Models\TrafficAlertState;
use App\Models\ConsentLog;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use SaKanjo\EasyMetrics\Metrics\Trend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class HeroStatsWidget extends BaseStatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        // Read date filters from dashboard
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->subDays(30))->startOfDay();
        $endDate   = Carbon::parse($this->filters['endDate'] ?? now())->endOfDay();
        $days = max(1, $startDate->diffInDays($endDate));
        $rangeLabel = $startDate->format('M j') . ' – ' . $endDate->format('M j');

        // ── 1. Domains ──────────
        $totalDomains = Domain::count();
        $domainTrend = Trend::make(Domain::query())
            ->range(min($days, 30))
            ->countByDays();

        // ── 2. Requests (filtered by date range) ──
        $totalRequests = TrafficMetric::whereBetween('bucket', [$startDate, $endDate])
            ->sum('request_count');
        $requestTrend = Trend::make(TrafficMetric::query())
            ->dateColumn('bucket')
            ->range(min($days, 30))
            ->sumByDays('request_count');

        // ── 3. Consent Rate ──────
        $consentRate = 0;
        if ($tenant) {
            $domainIds = $tenant->domains()->pluck('id');
            $total = ConsentLog::whereIn('domain_id', $domainIds)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();
            $accepted = ConsentLog::whereIn('domain_id', $domainIds)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('consent_type', 'all')
                ->count();
            $consentRate = $total > 0 ? round(($accepted / $total) * 100) : 0;
        }

        // ── 4. p95 Latency (filtered by date range) ────────
        $rawMetrics = DB::table('traffic_metrics')
            ->whereBetween('bucket', [$startDate, $endDate])
            ->select('latency_histogram')
            ->get();
        
        $mergedLatency = TrafficMetric::emptyHistogram();
        foreach ($rawMetrics as $m) {
            $parsed = json_decode($m->latency_histogram, true) 
                ?? TrafficMetric::emptyHistogram();
            $mergedLatency = TrafficMetric::mergeHistograms($mergedLatency, $parsed);
        }
        $p95 = TrafficMetric::percentileFromHistogram($mergedLatency, 95);
        $latencyColor = $p95 < 500 ? 'success' : ($p95 < 2000 ? 'warning' : 'danger');

        // ── 5. Health Summary (open alerts + unresolved errors) ──
        $openAlerts = TrafficAlertState::whereIn('state', [
            TrafficAlertState::STATE_OPEN,
            TrafficAlertState::STATE_SUPPRESSED,
        ])->count();
        $alertColor = $openAlerts > 0 ? 'danger' : 'success';

        return [
            Stat::make('Domains', $totalDomains)
                ->description('Active tenants')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('info')
                ->chart($domainTrend->getData()),

            Stat::make('Requests', number_format($totalRequests))
                ->description($rangeLabel)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart($requestTrend->getData()),

            Stat::make('Consent Rate', "{$consentRate}%")
                ->description('Accept rate')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($consentRate >= 80 ? 'success' : 'warning'),

            Stat::make('p95 Latency', "{$p95}ms")
                ->description($p95 < 500 ? 'Healthy' : ($p95 < 2000 ? 'Elevated' : 'Critical'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($latencyColor),

                Stat::make('Health', $openAlerts > 0 ? "{$openAlerts} Alert(s)" : 'All Clear')
                ->description($openAlerts > 0 ? 'Needs attention' : 'No issues')
                ->descriptionIcon($openAlerts > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($alertColor),

            Stat::make($this->getInfrastructureName(), $this->getInfrastructureHeading())
                ->description($this->getInfrastructureDescription())
                ->descriptionIcon($this->getInfrastructureIcon())
                ->color($this->getInfrastructureColor()),
        ];
    }

    protected function getInfrastructureName(): string
    {
        return $this->getInfrastructureState()['name'] ?? 'ycookies-proxy';
    }

    protected function getInfrastructureHeading(): string
    {
        return $this->getInfrastructureState()['heading'];
    }

    protected function getInfrastructureDescription(): string
    {
        return $this->getInfrastructureState()['description'];
    }

    protected function getInfrastructureIcon(): string
    {
        return $this->getInfrastructureState()['icon'];
    }

    protected function getInfrastructureColor(): string
    {
        return $this->getInfrastructureState()['color'];
    }

    protected function getInfrastructureState(): array
    {
        $default = [
            'name' => 'ycookies-proxy',
            'heading' => 'All Running',
            'color' => 'success',
            'description' => 'Proxy is online',
            'icon' => 'heroicon-m-server-stack'
        ];

        try {
            if (! \App\Models\CoolifySetting::instance()->isConfigured()) {
                return [
                    'name' => 'ycookies-proxy',
                    'heading' => 'Setup Required',
                    'color' => 'warning',
                    'description' => 'Coolify not configured',
                    'icon' => 'heroicon-m-exclamation-triangle'
                ];
            }

            $service = app(\App\Services\CoolifyApiService::class);
            $result = $service->getApplications();
            // Suppress error if API cache simply fails quietly
            if (empty($result['apps']) && $result['error']) {
                return [
                    'name' => 'ycookies-proxy',
                    'heading' => 'API Error',
                    'color' => 'danger',
                    'description' => 'Cannot fetch status',
                    'icon' => 'heroicon-m-x-circle'
                ];
            }

            $apps = $result['apps'] ?? [];
            $settings = \App\Models\CoolifySetting::instance();
            $monitoredUuids = $settings->app_uuids ?? [];
            
            $proxyName = 'ycookies-proxy';
            if ($settings->primary_proxy_uuid) {
                // Find primary proxy name
                $primaryApp = collect($apps)->firstWhere('uuid', $settings->primary_proxy_uuid);
                if ($primaryApp) {
                    $proxyName = $primaryApp['name'];
                }
            }

            if (!empty($monitoredUuids)) {
                $apps = array_filter($apps, fn ($app) => in_array($app['uuid'], $monitoredUuids));
            }

            $errors = 0;
            $warnings = 0;
            $totalCount = count($apps);

            foreach ($apps as $app) {
                $parsed = \App\Services\CoolifyApiService::parseStatus($app['status'] ?? 'unknown');
                if ($parsed['color'] === 'red') $errors++;
                elseif ($parsed['color'] === 'amber') $warnings++;
            }

            if ($errors > 0 || $totalCount === 0) {
                return [
                    'name' => $proxyName,
                    'heading' => $errors > 0 ? "{$errors} Down" : 'No Apps',
                    'color' => 'danger',
                    'description' => $errors > 0 ? 'Critical failure' : 'No monitored servers',
                    'icon' => 'heroicon-m-x-circle'
                ];
            }

            if ($warnings > 0) {
                return [
                    'name' => $proxyName,
                    'heading' => "{$warnings} Issue(s)",
                    'color' => 'warning',
                    'description' => 'Degraded performance',
                    'icon' => 'heroicon-m-exclamation-triangle'
                ];
            }

            $default['name'] = $proxyName;
            return $default;
        } catch (\Throwable $e) {
            return [
                'name' => 'ycookies-proxy',
                'heading' => 'Error',
                'color' => 'danger',
                'description' => 'Connection failed',
                'icon' => 'heroicon-m-x-circle'
            ];
        }
    }
}
