<?php

namespace App\Filament\Resources\Domains\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (\App\Models\Domain $record) => $record->cookieBar?->name ?? 'No Theme Assigned'),
                
                \Filament\Tables\Columns\IconColumn::make('status_icon')
                    ->label('Status')
                    ->icon(fn (\App\Models\Domain $record) => match(true) {
                        !$record->is_active => 'heroicon-o-pause-circle',
                        $record->health_status === 'healthy' => 'heroicon-m-check-circle',
                        $record->health_status === 'warning' => 'heroicon-m-exclamation-triangle',
                        $record->health_status === 'failing' => 'heroicon-m-x-circle',
                        default => 'heroicon-m-minus-circle',
                    })
                    ->color(fn (\App\Models\Domain $record) => match(true) {
                        !$record->is_active => 'gray',
                        $record->health_status === 'healthy' => 'success',
                        $record->health_status === 'warning' => 'warning',
                        $record->health_status === 'failing' => 'danger',
                        default => 'gray',
                    }),
                
                \Filament\Tables\Columns\ToggleColumn::make('is_active')
                    ->afterStateUpdated(function ($record, $state) {
                        if ($state) {
                            \App\Services\TelemetryService::syncDomain($record);
                        }
                    }),
                    
                TextColumn::make('site_id')
                    ->searchable()
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Site ID copied to clipboard')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('last_traffic_at')
                    ->label('Last Traffic')
                    ->getStateUsing(fn (\App\Models\Domain $record) => 
                        \App\Models\TrafficMetric::where('domain_id', $record->id)->latest('bucket')->value('bucket')
                    )
                    ->since()
                    ->sortable()
                    ->toggleable()
                    ->placeholder('Never'),

                TextColumn::make('health_status')
                    ->label('Health')
                    ->extraHeaderAttributes(['title' => 'Result of the latest automated uptime check.'])
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'failing' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_scanned_at')
                    ->label('Last Scanned')
                    ->extraHeaderAttributes(['title' => 'When the last automated health check ran for this domain.'])
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('activate_all')
                        ->label('Activate Selected')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->action(fn (\Illuminate\Database\Eloquent\Collection $records) => $records->each(fn ($d) => $d->update(['is_active' => true]))),
                    \Filament\Actions\BulkAction::make('run_health_check')
                        ->label('Run Health Check')
                        ->icon('heroicon-o-heart')
                        ->action(fn (\Illuminate\Database\Eloquent\Collection $records) => \Illuminate\Support\Facades\Artisan::call('ycookies:check-health')),
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
