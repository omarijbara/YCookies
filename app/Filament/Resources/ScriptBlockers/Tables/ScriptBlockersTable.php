<?php

namespace App\Filament\Resources\ScriptBlockers\Tables;

use App\Models\ScriptBlocker;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScriptBlockersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('blocker_type')
                    ->label(__('ycookies.script_blocker.blocker_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        ScriptBlocker::TYPE_STYLE => __('ycookies.script_blocker.type_style'),
                        default => __('ycookies.script_blocker.type_script'),
                    })
                    ->color(fn (?string $state): string => $state === ScriptBlocker::TYPE_STYLE ? 'warning' : 'info')
                    ->sortable(),
                TextColumn::make('key')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Status')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('service.name')
                    ->label('Service')
                    ->searchable()
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
            ->filters([
                SelectFilter::make('blocker_type')
                    ->label(__('ycookies.script_blocker.blocker_type'))
                    ->options([
                        ScriptBlocker::TYPE_SCRIPT => __('ycookies.script_blocker.type_script'),
                        ScriptBlocker::TYPE_STYLE => __('ycookies.script_blocker.type_style'),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name', 'asc');
    }
}
