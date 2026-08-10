<?php

namespace App\Filament\ConsentWidgets;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use App\Models\ConsentLog;
use App\Models\Domain;

class ConsentGroupsChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Cookie Group Acceptance Breakdown';
    protected ?string $pollingInterval = null;
    protected static ?int $sort = 3;

    protected function getFilters(): ?array
    {
        return [
            'today' => 'Today',
            '7_days' => 'Last 7 Days',
            '30_days' => 'Last 30 Days',
            'all' => 'All Time',
        ];
    }

    protected function getData(): array
    {
        $domainId = $this->filters['domain_id'] ?? Domain::where('is_active', true)->first()?->id;

        if (!$domainId) {
            return ['datasets' => [], 'labels' => []];
        }

        $query = ConsentLog::where('domain_id', $domainId)->latestConsent();

        $activeFilter = $this->filter;
        if ($activeFilter === 'today') {
            $query->whereDate('created_at', now()->toDateString());
        } elseif ($activeFilter === '7_days') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($activeFilter === '30_days' || !$activeFilter) {
            // Default to 30 days if null, or if explicitly requested
            $query->where('created_at', '>=', now()->subDays(30));
        }

        // Get the active consents based on the filter
        $logs = $query->get();
        
        $groupCounts = [];
        
        foreach ($logs as $log) {
            $granted = $log->consents_granted ?? [];
            foreach ($granted as $key => $value) {
                // If it's an object `{"essential": true, "marketing": false}`
                if (is_string($key)) {
                    if ($value) {
                        if (!isset($groupCounts[$key])) $groupCounts[$key] = 0;
                        $groupCounts[$key]++;
                    }
                } else {
                    // If it's a flat list `["essential", "marketing"]`
                    if (!isset($groupCounts[$value])) $groupCounts[$value] = 0;
                    $groupCounts[$value]++;
                }
            }
        }
        
        // Sort alphabetically or logically. For a bar chart, maintaining a consistent order 
        // (e.g., descending count) ensures the most accepted are on the left.
        arsort($groupCounts);

        $labels = array_map(function($l) { return ucfirst(str_replace('-', ' ', $l)); }, array_keys($groupCounts));
        $values = array_values($groupCounts);

        return [
            'datasets' => [
                [
                    'label' => 'Total Consents',
                    'data' => $values,
                    'backgroundColor' => [
                        '#d97777', // Rose/Reddish
                        '#d9aa6c', // Orange/Brownish
                        '#a8c767', // Green
                        '#6dbbcc', // Light Blue
                        '#999999', // Gray (for unclassified etc)
                        '#c78bb9', // Pink/Purple
                        '#3b82f6'
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
