<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use Filament\Resources\Pages\EditRecord;

class EditWebhookEndpoint extends EditRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['secret'])) {
            unset($data['secret']);
        }

        return $data;
    }
}
