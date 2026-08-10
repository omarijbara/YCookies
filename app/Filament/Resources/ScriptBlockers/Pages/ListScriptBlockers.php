<?php

namespace App\Filament\Resources\ScriptBlockers\Pages;

use App\Filament\Resources\ScriptBlockers\ScriptBlockerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScriptBlockers extends ListRecords
{
    protected static string $resource = ScriptBlockerResource::class;

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.list.script_and_style_blockers');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
