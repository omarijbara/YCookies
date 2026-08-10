<?php

namespace App\Filament\Resources\ScriptBlockers\Pages;

use App\Filament\Concerns\HasTranslatableContent;
use App\Filament\Resources\ScriptBlockers\ScriptBlockerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScriptBlocker extends CreateRecord
{
    use HasTranslatableContent;

    protected static string $resource = ScriptBlockerResource::class;
}
