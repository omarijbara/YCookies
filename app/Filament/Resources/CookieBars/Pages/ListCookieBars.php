<?php

namespace App\Filament\Resources\CookieBars\Pages;

use App\Filament\Resources\CookieBars\CookieBarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCookieBars extends ListRecords
{
    protected static string $resource = CookieBarResource::class;

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.list.cookie_bars');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
