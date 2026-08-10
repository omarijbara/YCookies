<?php

namespace App\Filament\Resources\ScriptBlockers\Pages;

use App\Filament\Concerns\HasTranslatableContent;
use App\Filament\Resources\ScriptBlockers\ScriptBlockerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditScriptBlocker extends EditRecord
{
    use HasTranslatableContent;

    protected static string $resource = ScriptBlockerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
