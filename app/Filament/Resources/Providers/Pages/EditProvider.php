<?php

namespace App\Filament\Resources\Providers\Pages;

use App\Filament\Concerns\HasTranslatableContent;
use App\Filament\Resources\Providers\ProviderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditProvider extends EditRecord
{
    use HasTranslatableContent;
    protected static string $resource = ProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => ! $this->record->is_library || $this->record->services()->count() === 0)
                ->before(function (DeleteAction $action) {
                    if ($this->record->is_library && $this->record->services()->count() > 0) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot delete library provider')
                            ->body("This provider was installed from the library. Delete the associated services first — the provider will be removed automatically.")
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
