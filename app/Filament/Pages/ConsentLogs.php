<?php

namespace App\Filament\Pages;

use App\Filament\Exports\ConsentLogExporter;
use App\Models\ConsentLog;
use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

class ConsentLogs extends Page implements HasTable
{
    protected static ?int $navigationSort = 7;

    use InteractsWithTable;

    protected string $view = 'filament.pages.consent-logs';

    /**
     * Override standard pagination with Simple Pagination.
     * Prevents Filament from running devastating COUNT(*) aggregations on millions of
     * consent logs under heavy admin traffic, totally eliminating 504 Gateway Timeouts.
     */
    protected function paginateTableQuery(Builder $query): \Illuminate\Contracts\Pagination\Paginator
    {
        return $query->simplePaginate(
            $this->getTableRecordsPerPage() === 'all' ? 100 : $this->getTableRecordsPerPage()
        );
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.consent');
    }

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.system.consent_logs');
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.consent_logs');
    }

    /**
     * Resolve service keys to their display names grouped by CookieGroup.
     * Returns: ['Marketing' => ['Google Analytics', 'Facebook Pixel'], 'Essential' => ['Borlabs Cookie']]
     */
    protected function resolveServicesByGroup(array $serviceKeys): array
    {
        if (empty($serviceKeys)) {
            return [];
        }

        $services = Service::with('cookieGroup')
            ->whereIn('key', $serviceKeys)
            ->get();

        $grouped = [];
        $resolved = [];

        foreach ($services as $service) {
            $groupName = $service->cookieGroup?->name ?? 'Other';
            $grouped[$groupName][] = $service->name;
            $resolved[] = $service->key;
        }

        // Any keys not found in the DB are shown under "Unknown"
        $unresolved = array_diff($serviceKeys, $resolved);
        if (! empty($unresolved)) {
            $grouped['Unknown'] = array_values($unresolved);
        }

        return $grouped;
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $domainIds = $tenant instanceof Group
            ? Domain::where('group_id', $tenant->id)->pluck('id')
            : collect();

        return $table
            ->query(
                ConsentLog::query()
                    ->whereIn('domain_id', $domainIds)
                    ->latest()
            )
            ->columns([
                TextColumn::make('consent_uid')
                    ->label('UID')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('UID copied')
                    ->fontFamily('mono')
                    ->limit(28)
                    ->tooltip(fn ($record) => $record->consent_uid),

                TextColumn::make('cookie_version')
                    ->label('Cookie Version')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('services_granted')
                    ->label('Service Consents')
                    ->formatStateUsing(function ($state) {
                        if (is_array($state)) {
                            return count($state);
                        }

                        return '0';
                    })
                    ->alignCenter(),

                TextColumn::make('consent_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'all' => 'success',
                        'essential' => 'info',
                        'custom' => 'warning',
                        'renewed' => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('is_latest')
                    ->label('Latest')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('Date & Time')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('consent_type')
                    ->label('Consent Type')
                    ->options([
                        'all' => 'Accept All',
                        'essential' => 'Essential Only',
                        'custom' => 'Custom Settings',
                        'renewed' => 'Renewed',
                    ]),

                SelectFilter::make('domain_id')
                    ->label('Domain')
                    ->options(function () use ($domainIds) {
                        return Domain::whereIn('id', $domainIds)->pluck('name', 'id');
                    }),

                Filter::make('is_latest')
                    ->label('Latest Only')
                    ->query(fn (Builder $query) => $query->where('is_latest', true))
                    ->toggle()
                    ->default(false),

                Filter::make('created_from')
                    ->form([
                        DatePicker::make('created_from')->label('From'),
                        DatePicker::make('created_until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Action::make('viewDetails')
                    ->label('Details')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn ($record) => 'Consent Details')
                    ->modalContent(function ($record) {
                        $servicesByGroup = $this->resolveServicesByGroup(
                            is_array($record->services_granted) ? $record->services_granted : []
                        );

                        return view('filament.pages.consent-log-details', [
                            'log' => $record,
                            'servicesByGroup' => $servicesByGroup,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('lg'),

                Action::make('viewHistory')
                    ->label('History')
                    ->icon('heroicon-o-clock')
                    ->modalHeading(fn ($record) => 'Consent History: '.substr($record->consent_uid, 0, 16).'…')
                    ->modalContent(function ($record) {
                        $history = ConsentLog::getHistory($record->consent_uid, $record->domain_id);

                        return view('filament.pages.consent-log-history', [
                            'history' => $history,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('lg'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->poll('30s')
            ->headerActions([
                ExportAction::make()
                    ->exporter(ConsentLogExporter::class)
                    ->icon('heroicon-o-arrow-down-tray')
                    ->label('Export logs'),
                Action::make('runRetentionPurge')
                    ->label(__('ycookies.system.purge_consent_logs_action'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => (bool) Filament::getTenant() && (auth()->user()?->hasRole('super_admin') ?? false))
                    ->requiresConfirmation()
                    ->modalHeading(__('ycookies.system.purge_consent_logs_modal_heading'))
                    ->modalDescription(__('ycookies.system.purge_consent_logs_modal_description'))
                    ->action(function (): void {
                        $group = Filament::getTenant();
                        if (! $group instanceof Group) {
                            return;
                        }
                        $exit = Artisan::call('ycookies:purge-consent-logs', [
                            '--group' => (string) $group->id,
                        ]);
                        $output = trim(Artisan::output());
                        if ($exit === 0) {
                            Notification::make()
                                ->title(__('ycookies.system.purge_consent_logs_success'))
                                ->body($output !== '' ? $output : null)
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title(__('ycookies.system.purge_consent_logs_failed'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
