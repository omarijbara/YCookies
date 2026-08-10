<?php

namespace App\Filament\ConsentWidgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use App\Models\ConsentLog;
use App\Models\Domain;

class ConsentStatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;
    
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $domainId = $this->filters['domain_id'] ?? Domain::where('is_active', true)->first()?->id;

        if (!$domainId) {
            return [];
        }

        $query = ConsentLog::where('domain_id', $domainId)->latestConsent();

        $totalConsents = (clone $query)->count();
        $acceptAll = (clone $query)->where('consent_type', 'all')->count();
        $essentialOnly = (clone $query)->where('consent_type', 'essential')->count();

        $acceptRate = $totalConsents > 0 ? round(($acceptAll / $totalConsents) * 100, 1) : 0;
        $essentialRate = $totalConsents > 0 ? round(($essentialOnly / $totalConsents) * 100, 1) : 0;

        return [
            Stat::make('Total Consents (Active Users)', number_format($totalConsents))
                ->description('Unique visitors with saved choices')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Accept All Rate', "{$acceptRate}%")
                ->description(number_format($acceptAll) . " users accepted all cookies")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Essential Only Rate', "{$essentialRate}%")
                ->description(number_format($essentialOnly) . " users rejected optional cookies")
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color('warning'),
        ];
    }
}
