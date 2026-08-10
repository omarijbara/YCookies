<?php

namespace App\Filament\Resources\Groups\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('consent_retention_days')
                    ->label('Log Retention')
                    ->suffix(' days')
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
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('gdprExport')
                    ->label('GDPR Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->action(function (\App\Models\Group $record, \App\Services\GdprService $service) {
                        $path = $service->exportGroup($record);
                        return response()->download($path);
                    }),
                Action::make('gdprDelete')
                    ->label('GDPR Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete Group & ALL Data')
                    ->modalDescription('This will permanently delete the group, all associated domains, configurations, and PURGE all consent logs. This action satisfies GDPR "Right to be Forgotten". Are you sure?')
                    ->action(function (\App\Models\Group $record, \App\Services\GdprService $service) {
                        $service->deleteGroup($record);
                        \Filament\Notifications\Notification::make()
                            ->title('Group and all data deleted.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
