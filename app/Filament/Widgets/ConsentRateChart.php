<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\ConsentLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Filament\Facades\Filament;

class ConsentRateChart extends ChartWidget
{
    protected ?string $heading = 'Consent Interactions (Last 30 Days)';
    
    protected static ?int $sort = 20;

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (!$tenant) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $domainIds = $tenant->domains()->pluck('id');

        $startDate = Carbon::now()->subDays(30)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $metrics = ConsentLog::whereIn('domain_id', $domainIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                'consent_type',
                DB::raw('count(*) as aggregate')
            )
            ->groupBy('date', 'consent_type')
            ->get();

        $labels = [];
        $explicitData = [];
        $implicitData = [];

        for ($i = 0; $i < 30; $i++) {
            $date = Carbon::now()->subDays(29 - $i)->format('Y-m-d');
            $labels[] = Carbon::parse($date)->format('M d');
            
            $explicitData[$date] = 0;
            $implicitData[$date] = 0;
        }

        foreach ($metrics as $metric) {
            $dateStr = is_string($metric->date) ? $metric->date : Carbon::parse($metric->date)->format('Y-m-d');
            
            if (isset($explicitData[$dateStr])) {
                if (in_array($metric->consent_type, ['explicit', 'renewed', 'custom'])) {
                    $explicitData[$dateStr] += $metric->aggregate;
                } else {
                    $implicitData[$dateStr] += $metric->aggregate;
                }
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Active Decisions (Accepted/Rejected)',
                    'data' => array_values($explicitData),
                    'borderColor' => '#10b981',
                    'fill' => false,
                ],
                [
                    'label' => 'Banner Views (Ignored/Implicit)',
                    'data' => array_values($implicitData),
                    'borderColor' => '#6b7280',
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
