<?php

namespace App\Filament\Resources\Services\Tables;

use App\Services\TemplateLibraryService;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

use Illuminate\Database\Eloquent\Collection;

use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\BulkAction;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domains.name')
                    ->label(__('ycookies.table.domain'))
                    ->sortable()
                    ->badge()
                    ->color('info'),
                TextColumn::make('cookieGroup.name')
                    ->label(__('ycookies.table.cookie_group'))
                    ->sortable()
                    ->badge(),
                TextColumn::make('name')
                    ->label(__('ycookies.table.name'))
                    ->searchable()
                    ->icon(fn ($record) => match (strtolower($record->cookieGroup?->name ?? '')) {
                        'marketing', 'ads' => 'heroicon-m-megaphone',
                        'statistics', 'analytics' => 'heroicon-m-chart-bar',
                        'essential', 'necessary' => 'heroicon-m-lock-closed',
                        'functional', 'preferences' => 'heroicon-m-adjustments-horizontal',
                        default => 'heroicon-m-puzzle-piece',
                    })
                    ->iconColor('primary')
                    ->weight('semibold')
                    ->limit(30),
                TextColumn::make('provider.name')
                    ->label(__('ycookies.table.provider'))
                    ->searchable()
                    ->color('gray')
                    ->limit(20),
                TextColumn::make('template_key')
                    ->label(__('ycookies.table.source'))
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->template_key ? __('ycookies.table.library') : __('ycookies.table.custom'))
                    ->color(fn ($record) => $record->template_key ? 'success' : 'gray')
                    ->icon(fn ($record) => $record->template_key ? 'heroicon-m-book-open' : 'heroicon-m-wrench-screwdriver'),
                TextColumn::make('template_version')
                    ->label(__('ycookies.table.update'))
                    ->badge()
                    ->getStateUsing(function ($record) {
                        $update = $record->getAvailableUpdate();
                        if ($update) {
                            return "Update → {$update}";
                        }
                        if ($record->isFromLibrary()) {
                            return "v{$record->template_version}";
                        }
                        return '—';
                    })
                    ->color(function ($record) {
                        if ($record->getAvailableUpdate()) {
                            return 'warning';
                        }
                        if ($record->isFromLibrary()) {
                            return 'success';
                        }
                        return 'gray';
                    })
                    ->icon(function ($record) {
                        if ($record->getAvailableUpdate()) {
                            return 'heroicon-m-arrow-up-circle';
                        }
                        return null;
                    }),
                \Filament\Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('ycookies.table.is_active')),
                TextColumn::make('sort_order')
                    ->label(__('ycookies.table.sort_order'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->paginated([50, 100, 250, 'all'])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('domain_id')
                    ->relationship('domains', 'name')
                    ->label(__('ycookies.table.filter_by_domain'))
                    ->searchable()
                    ->preload(),
                \Filament\Tables\Filters\SelectFilter::make('cookie_group_id')
                    ->relationship('cookieGroup', 'name')
                    ->label(__('ycookies.table.filter_by_group'))
                    ->searchable()
                    ->preload(),
                \Filament\Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('ycookies.table.active_status')),
                \Filament\Tables\Filters\TernaryFilter::make('is_library')
                    ->label(__('ycookies.table.service_source'))
                    ->placeholder(__('ycookies.table.all'))
                    ->trueLabel(__('ycookies.table.library'))
                    ->falseLabel(__('ycookies.table.custom'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('template_key'),
                        false: fn ($query) => $query->whereNull('template_key'),
                    ),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                    \Filament\Actions\BulkAction::make('updatePackages')
                        ->label(__('ycookies.table.update_packages'))
                        ->icon('heroicon-m-arrow-up-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->getAvailableUpdate()) {
                                    if ($record->updateFromTemplate()) {
                                        $count++;
                                    }
                                }
                            }
                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title("Updated {$count} package(s) to their latest template versions.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
