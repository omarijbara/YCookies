<?php

namespace App\Filament\Resources\ScriptBlockers\Pages;

use App\Filament\Resources\ScriptBlockers\ScriptBlockerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewScriptBlocker extends ViewRecord
{
    protected static string $resource = ScriptBlockerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
