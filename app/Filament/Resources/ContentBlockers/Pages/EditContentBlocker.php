<?php

namespace App\Filament\Resources\ContentBlockers\Pages;

use App\Filament\Concerns\HasTranslatableContent;
use App\Filament\Resources\ContentBlockers\ContentBlockerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContentBlocker extends EditRecord
{
    use HasTranslatableContent;

    protected static string $resource = ContentBlockerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn () => $this->record->is_system),
        ];
    }
}
