<?php

namespace App\Filament\Resources\GroupInvitations\Pages;

use App\Filament\Resources\GroupInvitations\GroupInvitationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGroupInvitation extends CreateRecord
{
    protected static string $resource = GroupInvitationResource::class;

    protected function afterCreate(): void
    {
        $invitation = $this->record;

        \Illuminate\Support\Facades\Mail::to($invitation->email)->send(
            new \App\Mail\GroupInvitationMail($invitation)
        );
    }
}
