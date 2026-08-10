<?php

namespace App\Filament\Resources\ContentBlockers\Tables;

use App\Models\ContentBlocker;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ContentBlockersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->color('gray'),
                ToggleColumn::make('is_active')
                    ->label('Status')
                    ->sortable()
                    ->disabled(fn (ContentBlocker $record) => $record->is_system),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ContentBlocker $record) => $record->is_system ? 'System · Cannot be deleted' : null),
                ImageColumn::make('preview_image_url')
                    ->label('Preview Image')
                    ->circular(),
                TextColumn::make('domain.name')
                    ->label('Domain')
                    ->sortable()
                    ->searchable()
                    ->default('All Domains')
                    ->color(fn (ContentBlocker $record) => $record->domain_id === null ? 'success' : null),
                TextColumn::make('service.name')
                    ->label('Service Context')
                    ->sortable()
                    ->badge()
                    ->color('warning'),
                TextColumn::make('provider.name')
                    ->label('Provider')
                    ->sortable()
                    ->badge()
                    ->color('info'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('is_system', 'desc')
            ->paginated([50, 100, 250, 'all'])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('domain_id')
                    ->relationship('domain', 'name')
                    ->label('Filter by Domain')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (ContentBlocker $record) => $record->is_system),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
