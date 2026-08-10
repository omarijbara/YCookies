<?php

namespace App\Filament\Resources\CookieGroups\Pages;

use App\Filament\Concerns\HasTranslatableContent;
use App\Filament\Resources\CookieGroups\CookieGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditCookieGroup extends EditRecord
{
    use HasTranslatableContent;
    protected static string $resource = CookieGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => ! $this->record->is_system)
                ->before(function (DeleteAction $action) {
                    if ($this->record->is_system) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot delete system group')
                            ->body('This is a predefined system group required for cookie bars to function correctly.')
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }

                    $serviceCount = $this->record->services()->count();
                    if ($serviceCount > 0) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot delete this group')
                            ->body("This group still has {$serviceCount} connected service(s). Please delete or move them to another group first.")
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
