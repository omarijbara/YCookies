<?php

namespace App\Filament\Resources\CookieBars\Pages;

use App\Filament\Resources\CookieBars\CookieBarResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCookieBar extends EditRecord
{
    protected static string $resource = CookieBarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Ensure the translations JSON always has the full nested structure
     * before Filament hydrates state into KeyValue fields.
     *
     * The CookieBar model's translations accessor already handles
     * default merging, so we just ensure it's properly hydrated.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // The model's translations accessor handles defaults and merging,
        // but we need to ensure the raw data gets through the accessor
        // during form hydration. Re-access via model to trigger accessor.
        $record = $this->getRecord();
        if ($record) {
            $data['translations'] = $record->translations;
        }

        return $data;
    }
}
