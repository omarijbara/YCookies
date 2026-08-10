<?php

namespace App\Filament\Resources\Providers\Pages;

use App\Filament\Concerns\HasTranslatableContent;
use App\Filament\Resources\Providers\ProviderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProvider extends CreateRecord
{
    use HasTranslatableContent;

    protected static string $resource = ProviderResource::class;
}
