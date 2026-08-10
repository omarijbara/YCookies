<?php

namespace App\Filament\Pages;

use App\Models\Domain;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class ConsentDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 6;

    protected static string $routePath = 'consent-statistics';

    protected static ?string $slug = 'consent-statistics';

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.consent');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.system.consent_statistics');
    }

    public function getTitle(): string
    {
        return __('ycookies.system.consent_statistics');
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.consent_statistics');
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('export_pdf')
                ->label('Export PDF Report')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    \Filament\Notifications\Notification::make()
                        ->title('Export queued')
                        ->body('This feature is currently mocking a PDF export.')
                        ->info()
                        ->send();
                }),
            \Filament\Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->action(function () {
                    \Filament\Notifications\Notification::make()
                        ->title('Export queued')
                        ->body('This feature is currently mocking a CSV export.')
                        ->info()
                        ->send();
                }),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('domain_id')
                    ->label('Filter by Domain')
                    ->options(Domain::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->default(Domain::where('is_active', true)->first()?->id),
            ])
            ->columns(3);
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\ConsentWidgets\ConsentStatsOverview::class,
            \App\Filament\ConsentWidgets\ConsentTrendChart::class,
            \App\Filament\ConsentWidgets\ConsentGroupsChart::class,
        ];
    }
}
