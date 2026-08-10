<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\ConsentLog;
use App\Models\Domain;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

class ConsentStatisticsChart extends ChartWidget
{
    protected ?string $heading = 'Consent Acceptance Rates';

    protected static ?int $sort = 10;
    protected static bool $isLazy = true;

    protected int | string | array $columnSpan = ['md' => 1, 'xl' => 1];

    protected function getData(): array
    {
        $tenant = Filament::getTenant();

        if (!$tenant) {
            return ['datasets' => [], 'labels' => []];
        }

        $domainIds = Domain::where('group_id', $tenant->id)->pluck('id');

        // Cache stats for 5 minutes per tenant
        $cacheKey = "consent_stats_chart:{$tenant->id}";

        return Cache::remember($cacheKey, 300, function () use ($domainIds) {
            $allCount = ConsentLog::whereIn('domain_id', $domainIds)
                ->where('consent_type', 'all')
                ->where('is_latest', true)
                ->count();

            $essentialCount = ConsentLog::whereIn('domain_id', $domainIds)
                ->where('consent_type', 'essential')
                ->where('is_latest', true)
                ->count();

            $customCount = ConsentLog::whereIn('domain_id', $domainIds)
                ->where('consent_type', 'custom')
                ->where('is_latest', true)
                ->count();

            if ($allCount === 0 && $essentialCount === 0 && $customCount === 0) {
                return [
                    'datasets' => [
                        [
                            'data' => [1],
                            'backgroundColor' => ['#374151'],
                            'label' => 'No Data',
                        ],
                    ],
                    'labels' => ['No Data Yet'],
                ];
            }

            return [
                'datasets' => [
                    [
                        'label' => 'Consent Rates',
                        'data' => [$allCount, $essentialCount, $customCount],
                        'backgroundColor' => [
                            '#10b981', // Emerald (Accept All)
                            '#6366f1', // Indigo (Essential Only)
                            '#f59e0b', // Amber (Custom)
                        ],
                        'borderWidth' => 0,
                        'hoverOffset' => 4,
                    ],
                ],
                'labels' => ['Accept All', 'Essential Only', 'Custom Settings'],
            ];
        });
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
