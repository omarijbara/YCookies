<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Resources\Domains\Widgets\DomainMetricsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('rotate_origin_secret')
                ->label('Rotate Origin Secret')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Rotate Origin Authentication Secret')
                ->modalDescription('This will generate a new secret. The old secret will remain valid for a 24-hour grace period to ensure zero downtime while you update your origin server configuration.')
                ->modalSubmitActionLabel('Yes, Rotate Secret')
                ->action(function (\App\Models\Domain $record) {
                    $record->origin_auth_token_legacy = $record->origin_auth_token;
                    $record->origin_auth_legacy_expires_at = now()->addHours(24);
                    $record->origin_auth_token = \Illuminate\Support\Str::random(40);
                    $record->config_version++;
                    $record->save();

                    \Filament\Notifications\Notification::make()
                        ->title('Secret Rotated Successfully')
                        ->body('The new secret has been generated. Please update your origin server configuration within 24 hours.')
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
