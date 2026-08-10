<?php

namespace App\Filament\Resources\WebhookEndpoints\Tables;

use App\Models\WebhookEndpoint;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WebhookEndpointsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('ycookies.webhook_endpoint.name'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('url')
                    ->label(__('ycookies.webhook_endpoint.url'))
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->url)
                    ->searchable(),
                TextColumn::make('events')
                    ->label(__('ycookies.webhook_endpoint.events'))
                    ->formatStateUsing(function ($state): string {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }
                        $labels = WebhookEndpoint::eventOptions();

                        return collect($state)->map(fn (string $e) => $labels[$e] ?? $e)->join(', ');
                    }),
                IconColumn::make('is_active')
                    ->label(__('ycookies.webhook_endpoint.active'))
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label(__('ycookies.webhook_endpoint.updated'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
