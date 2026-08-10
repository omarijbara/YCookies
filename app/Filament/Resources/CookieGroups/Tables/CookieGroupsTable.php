<?php

namespace App\Filament\Resources\CookieGroups\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CookieGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('key')
                    ->searchable()
                    ->color('gray'),
                TextColumn::make('is_system')
                    ->label('Type')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->is_system ? 'System' : 'Custom')
                    ->color(fn ($record) => $record->is_system ? 'info' : 'gray')
                    ->icon(fn ($record) => $record->is_system ? 'heroicon-m-shield-check' : 'heroicon-m-wrench-screwdriver'),
                \Filament\Tables\Columns\ToggleColumn::make('is_required')
                    ->label('Required'),
                TextColumn::make('sort_order')
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
            ->defaultSort('sort_order', 'asc')
            ->checkIfRecordIsSelectableUsing(fn ($record) => ! $record->is_system)
            ->paginated([50, 100, 250, 'all'])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('domain_id')
                    ->relationship('domains', 'name')
                    ->label('Filter by Domain'),
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
