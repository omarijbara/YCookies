<?php

namespace App\Filament\Resources\TrafficAlerts;

use App\Filament\Resources\TrafficAlerts\Pages\ListTrafficAlerts;
use App\Filament\Resources\TrafficAlerts\Pages\ViewTrafficAlert;
use App\Filament\Resources\TrafficAlerts\Tables\TrafficAlertsTable;
use App\Models\TrafficAlertState;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrafficAlertResource extends Resource
{
    protected static ?string $model = TrafficAlertState::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Traffic Alerts';

    protected static ?string $modelLabel = 'Traffic Alert';

    protected static ?string $pluralModelLabel = 'Traffic Alerts';

    /**
     * Hidden from sidebar — accessible from Health Checker dashboard.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.domains_proxy');
    }

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();
        if (!$tenant) {
            return null;
        }

        // Extremely fast flat query instead of complex SQL joins on every page load
        $domainIds = \App\Models\Domain::where('group_id', $tenant->id)->pluck('id');

        $count = TrafficAlertState::whereIn('domain_id', $domainIds)
            ->whereIn('state', ['open', 'suppressed'])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        $tenant = Filament::getTenant();
        if (!$tenant) {
            return 'warning';
        }

        $domainIds = \App\Models\Domain::where('group_id', $tenant->id)->pluck('id');

        $hasCritical = TrafficAlertState::whereIn('domain_id', $domainIds)
            ->where('state', 'open')
            ->where('severity', 'critical')
            ->exists();

        return $hasCritical ? 'danger' : 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Scope through domain → group (HasOneThrough can't be auto-scoped by Filament)
        if ($tenant = Filament::getTenant()) {
            $query->whereHas('domain', fn (Builder $q) => $q->where('group_id', $tenant->getKey()));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return TrafficAlertsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrafficAlerts::route('/'),
            'view'  => ViewTrafficAlert::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Alerts are system-generated only
    }
}
