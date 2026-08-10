<?php

namespace App\Filament\Resources\Providers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('is_library')
                    ->label('Type')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->is_library ? 'Library' : 'Custom')
                    ->color(fn ($record) => $record->is_library ? 'info' : 'gray')
                    ->icon(fn ($record) => $record->is_library ? 'heroicon-m-book-open' : 'heroicon-m-wrench-screwdriver'),
                TextColumn::make('services_count')
                    ->label('Services')
                    ->counts('services')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'warning'),
                TextColumn::make('privacy_policy_url')
                    ->label('Privacy Policy')
                    ->limit(30)
                    ->url(fn ($record) => $record->privacy_policy_url, true)
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->checkIfRecordIsSelectableUsing(fn ($record) => ! $record->is_library || $record->services()->count() === 0)
            ->filters([
                //
            ])
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
