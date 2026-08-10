<?php

namespace App\Filament\Widgets\Monitoring;

use App\Filament\Resources\TrafficAlerts\Tables\TrafficAlertsTable;
use App\Models\TrafficAlertState;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TrafficAlertsWidget extends BaseWidget
{
    use \Filament\Widgets\Concerns\InteractsWithPageFilters;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public bool $showAll = false;

    public function table(Table $table): Table
    {
        $startDate = \Illuminate\Support\Carbon::parse($this->filters['startDate'] ?? now()->subDays(30))->startOfDay();
        $endDate   = \Illuminate\Support\Carbon::parse($this->filters['endDate'] ?? now())->endOfDay();

        $query = TrafficAlertState::query()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc');

        if (!$this->showAll) {
            $query->limit(5);
        }

        $configuredTable = TrafficAlertsTable::configure($table)
            ->query($query)
            ->paginated($this->showAll)
            ->emptyStateHeading('No Alerts')
            ->emptyStateDescription('Traffic patterns and endpoints are operating normally.')
            ->emptyStateIcon('heroicon-o-check-circle');

        // Clean up the dashboard view by removing extensive table controls
        if (!$this->showAll) {
            $configuredTable->filters([])
                ->actions([])
                ->bulkActions([]);
            
            // Note: Filament tables make columns searchable by default. We can disable the global search bar
            // by setting the boolean on table, or by disabling it recursively on columns if needed.
            $configuredTable->searchable(false);
        }

        return $configuredTable;
    }
}
