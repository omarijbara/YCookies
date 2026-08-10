<?php

namespace App\Filament\Widgets;

use App\Models\TrafficMetric;
use Filament\Widgets\ChartWidget;
use SaKanjo\EasyMetrics\Metrics\Trend;

class TrafficHistoryChart extends ChartWidget
{
    protected ?string $heading = 'Traffic & Error History';
    
    public ?string $filter = '30';

    protected static ?int $sort = 4; // After top actions
    protected static bool $isLazy = true;

    protected int | string | array $columnSpan = 2; // Keep 2/3 width

    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];
    }

    protected function getData(): array
    {
        $days = (int) $this->filter;

        $requestsTrend = Trend::make(TrafficMetric::query())
            ->dateColumn('bucket')
            ->range($days)
            ->sumByDays('request_count');

        $errorsTrend = Trend::make(TrafficMetric::query())
            ->dateColumn('bucket')
            ->range($days)
            ->sumByDays('status_5xx');

        return [
            'datasets' => [
                [
                    'label' => 'Total Requests',
                    'data' => $requestsTrend->getData(),
                    'fill' => 'start',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'borderColor' => 'rgb(59, 130, 246)',
                    'tension' => 0.4,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => '5xx Errors',
                    'data' => $errorsTrend->getData(),
                    'fill' => false,
                    'borderColor' => 'rgba(239, 68, 68, 0.8)',
                    'borderDash' => [5, 5],
                    'tension' => 0.4,
                    'yAxisID' => 'y1',
                ],
            ],
            'labels' => array_map(fn($date) => \Carbon\Carbon::parse($date)->format('M j'), $requestsTrend->getLabels()),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'type' => 'linear',
                    'display' => true,
                    'position' => 'left',
                ],
                'y1' => [
                    'type' => 'linear',
                    'display' => true,
                    'position' => 'right',
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
