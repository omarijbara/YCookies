<?php

namespace App\Filament\Resources\CookieBars\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Actions\ReplicateAction;
use Filament\Tables\Table;

class CookieBarsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\ColorColumn::make('primary_color')
                    ->label('Theme')
                    ->getStateUsing(fn ($record) => $record->theme_settings['colors']['primary'] ?? '#3b82f6'),
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('domains_count')
                    ->label('Domains')
                    ->counts('domains')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                \Filament\Actions\ReplicateAction::make()
                    ->label('Duplicate')
                    ->excludeAttributes(['name'])
                    ->beforeReplicaSaved(function (\Illuminate\Database\Eloquent\Model $replica): void {
                        $replica->name = $replica->name . ' (Copy)';
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
