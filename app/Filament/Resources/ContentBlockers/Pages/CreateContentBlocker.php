<?php

namespace App\Filament\Resources\ContentBlockers\Pages;

use App\Filament\Concerns\HasTranslatableContent;
use App\Filament\Resources\ContentBlockers\ContentBlockerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContentBlocker extends CreateRecord
{
    use HasTranslatableContent;

    protected static string $resource = ContentBlockerResource::class;
}
