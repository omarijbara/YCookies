<?php

namespace App\Filament\Resources\CookieGroups\Pages;

use App\Filament\Resources\CookieGroups\CookieGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCookieGroups extends ListRecords
{
    protected static string $resource = CookieGroupResource::class;

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.list.cookie_groups');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
