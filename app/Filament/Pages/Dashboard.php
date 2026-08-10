<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.workspace');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.dashboard');
    }

    public function getTitle(): string
    {
        return __('ycookies.resources.dashboard');
    }

    public function getColumns(): int|array
    {
        return 3;
    }

    public function filtersForm(Schema $form): Schema
    {
        return $form
            ->columns([
                'default' => 3,
                'sm' => 3,
                'md' => 3,
                'lg' => 3,
                'xl' => 3,
                '2xl' => 3,
            ])
            ->schema([
                Select::make('preset')
                    ->label('Period')
                    ->options([
                        'today'     => 'Today',
                        'yesterday' => 'Yesterday',
                        'last7'     => 'Last 7 days',
                        'last14'    => 'Last 14 days',
                        'last30'    => 'Last 30 days',
                        'last365'   => 'Last 365 days',
                    ])
                    ->default('last30')
                    ->afterStateUpdated(function ($state, callable $set) {
                        $end = now();
                        $start = match ($state) {
                            'today'     => now(),
                            'yesterday' => now()->subDay(),
                            'last7'     => now()->subDays(7),
                            'last14'    => now()->subDays(14),
                            'last30'    => now()->subDays(30),
                            'last365'   => now()->subDays(365),
                            default     => now()->subDays(30),
                        };
                        if ($state === 'yesterday') {
                            $end = now()->subDay();
                        }
                        $set('startDate', $start->format('Y-m-d'));
                        $set('endDate', $end->format('Y-m-d'));
                    })
                    ->live(),
                DatePicker::make('startDate')
                    ->label('From')
                    ->native(false)
                    ->default(now()->subDays(30)),
                DatePicker::make('endDate')
                    ->label('To')
                    ->native(false)
                    ->default(now()),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            // Section 1: Hero — above the fold
            \App\Filament\Widgets\HeroStatsWidget::class,
            \App\Filament\Widgets\SmartBannerWidget::class,
            \App\Filament\Widgets\QuickActionsWidget::class,

            // Section 2: Insights
            \App\Filament\Widgets\TrafficHistoryChart::class,
            \App\Filament\Widgets\ConsentOverviewWidget::class,

            // Section 3: Operations
            \App\Filament\Widgets\Monitoring\OperationsWidget::class,
        ];
    }
}
