<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\ConsentLog;
use App\Models\Domain;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

class ConsentCategoriesChart extends ChartWidget
{
    protected ?string $heading = 'Consent by Category';

    protected static ?int $sort = 11;
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
        $cacheKey = "consent_categories_chart:{$tenant->id}";

        return Cache::remember($cacheKey, 300, function () use ($domainIds) {
            // Because counting nested JSON arrays at runtime can be expensive,
            // we will pluck the consents_granted array for the latest consents and aggregate them in PHP.
            // If the dataset is massive, consider moving this to a dedicated aggregation table or job.
            
            $logs = ConsentLog::whereIn('domain_id', $domainIds)
                ->where('is_latest', true)
                ->pluck('consents_granted');

            $categories = [
                'marketing' => 0,
                'analytics' => 0,
                'preferences' => 0,
                'essential' => 0,
                'other' => 0,
            ];

            foreach ($logs as $granted) {
                if (!is_array($granted)) {
                    continue;
                }

                foreach ($granted as $category) {
                    $category = strtolower($category);
                    if (array_key_exists($category, $categories)) {
                        $categories[$category]++;
                    } else {
                        // Dynamically add unknown categories or map to 'other'
                        if (!in_array($category, ['all', 'custom'])) { // ignore meta types if mistakenly logged
                            $categories['other']++;
                        }
                    }
                }
            }

            // Remove categories with 0 count to keep chart clean
            $categories = array_filter($categories, fn($count) => $count > 0);

            if (empty($categories)) {
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

            // Generate colors
            $colors = [];
            $colorMap = [
                'essential' => '#6366f1',
                'marketing' => '#f43f5e',
                'analytics' => '#0ea5e9',
                'preferences' => '#8b5cf6',
                'other' => '#94a3b8',
            ];

            foreach (array_keys($categories) as $cat) {
                $colors[] = $colorMap[$cat] ?? sprintf('#%06X', mt_rand(0, 0xFFFFFF));
            }

            return [
                'datasets' => [
                    [
                        'label' => 'Total Consents',
                        'data' => array_values($categories),
                        'backgroundColor' => $colors,
                        'borderWidth' => 0,
                        'hoverOffset' => 4,
                    ],
                ],
                // Capitalize labels for display
                'labels' => array_map('ucfirst', array_keys($categories)),
            ];
        });
    }

    protected function getType(): string
    {
        return 'pie';
    }
}
