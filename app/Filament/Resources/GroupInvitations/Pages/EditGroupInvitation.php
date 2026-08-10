<?php

namespace App\Filament\Resources\GroupInvitations\Pages;

use App\Filament\Resources\GroupInvitations\GroupInvitationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGroupInvitation extends EditRecord
{
    protected static string $resource = GroupInvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
