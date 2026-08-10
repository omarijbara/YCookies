<?php

namespace App\Filament\Widgets;

use App\Models\ConsentLog;
use Filament\Widgets\ChartWidget;
use Filament\Facades\Filament;

class ConsentOverviewWidget extends ChartWidget
{
    protected ?string $heading = 'Consent Overview';
    protected static ?int $sort = 5;
    protected static bool $isLazy = true;
    protected int | string | array $columnSpan = 1; // 1/3 width to sit next to TrafficHistoryChart

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        $domainIds = $tenant ? $tenant->domains()->pluck('id') : collect();

        $activeConsents = ConsentLog::whereIn('domain_id', $domainIds)
            ->where('is_latest', true)
            ->get();

        $acceptAll = $activeConsents->where('consent_type', 'all')->count();
        $essential = $activeConsents->where('consent_type', 'essential')->count();
        $custom = $activeConsents->where('consent_type', 'custom')->count();
        
        $total = $acceptAll + $essential + $custom;

        if ($total === 0) {
            return [
                'datasets' => [
                    [
                        'data' => [1],
                        'backgroundColor' => ['rgba(156, 163, 175, 0.2)'], // Gray ring
                        'borderColor' => ['transparent'],
                    ]
                ],
                'labels' => ['No Data Found'],
            ];
        }

        return [
            'datasets' => [
                [
                    'data' => [$acceptAll, $essential, $custom],
                    'backgroundColor' => [
                        'rgba(16, 185, 129, 0.8)', // Emerald 500
                        'rgba(245, 158, 11, 0.8)', // Amber 500
                        'rgba(59, 130, 246, 0.8)', // Blue 500
                    ],
                    'borderColor' => [
                        'rgb(16, 185, 129)',
                        'rgb(245, 158, 11)',
                        'rgb(59, 130, 246)',
                    ],
                ],
            ],
            'labels' => ["Accept All ({$acceptAll})", "Essential ({$essential})", "Custom ({$custom})"],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
            'cutout' => '75%',
        ];
    }
}
