<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.list.services');
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('updateOutdated')
                ->label(__('Update Outdated Packages'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function () {
                    $services = \App\Models\Service::whereNotNull('template_version')->get();
                    $updated = 0;
                    foreach ($services as $service) {
                        if ($service->getAvailableUpdate()) {
                            if ($service->updateFromTemplate()) {
                                $updated++;
                            }
                        }
                    }
                    if ($updated > 0) {
                        \Filament\Notifications\Notification::make()
                            ->title("Updated {$updated} services to their latest versions.")
                            ->success()
                            ->send();
                    } else {
                        \Filament\Notifications\Notification::make()
                            ->title('All services are already up-to-date.')
                            ->info()
                            ->send();
                    }
                }),
            \Filament\Actions\Action::make('library')
                ->label(__('ycookies.resources.library'))
                ->icon('heroicon-o-book-open')
                ->color('primary')
                ->url(fn (): string => ServiceResource::getUrl('library')),
            CreateAction::make()
                ->label(__('ycookies.table.add_custom_service'))
                ->color('gray')
                ->icon('heroicon-o-plus'),
        ];
    }
}
