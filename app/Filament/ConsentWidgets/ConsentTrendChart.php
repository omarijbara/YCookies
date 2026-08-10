<?php

namespace App\Filament\ConsentWidgets;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use App\Models\ConsentLog;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ConsentTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Consent Activity (Last 30 Days)';
    protected ?string $pollingInterval = null;
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $domainId = $this->filters['domain_id'] ?? Domain::where('is_active', true)->first()?->id;

        if (!$domainId) {
            return ['datasets' => [], 'labels' => []];
        }

        // Group by day using standard Eloquent/DB raw to avoid extra dependencies
        $data = ConsentLog::where('domain_id', $domainId)
            ->where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('total', 'date');

        $labels = [];
        $values = [];
        
        // Fill the array with the last 30 days to ensure continuous X-axis
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = Carbon::parse($date)->format('M d');
            $values[] = $data[$date] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'New Consents Logged',
                    'data' => $values,
                    'fill' => 'start',
                    'tension' => 0.4,
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
