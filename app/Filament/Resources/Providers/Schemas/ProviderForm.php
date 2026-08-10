<?php

namespace App\Filament\Resources\Providers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Textarea::make('address')
                    ->columnSpanFull(),
                TextInput::make('privacy_policy_url')
                    ->url(),
                TextInput::make('cookie_policy_url')
                    ->url(),
                TextInput::make('opt_out_url')
                    ->url(),
            ]);
    }
}
