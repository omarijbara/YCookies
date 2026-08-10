<?php

namespace App\Filament\Resources\CookieGroups\Pages;

use App\Filament\Concerns\HasTranslatableContent;
use App\Filament\Resources\CookieGroups\CookieGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCookieGroup extends CreateRecord
{
    use HasTranslatableContent;

    protected static string $resource = CookieGroupResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mutateFormDataBeforeSave($data);
    }
}
