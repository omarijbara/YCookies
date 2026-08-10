<?php

namespace App\Filament\Resources\ContentBlockers\Pages;

use App\Filament\Resources\ContentBlockers\ContentBlockerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContentBlockers extends ListRecords
{
    protected static string $resource = ContentBlockerResource::class;

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.list.content_blockers');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
