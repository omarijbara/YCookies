<?php

namespace App\Filament\Resources\Groups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Section;
use Filament\Schemas\Schema;

class GroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Section::make('Group Details')
                    ->components([
                        TextInput::make('name')
                            ->required(),
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),
                \Filament\Forms\Components\Section::make('Data & Privacy Settings')
                    ->components([
                        TextInput::make('consent_retention_days')
                            ->label('Consent Retention (Days)')
                            ->helperText('Number of days before old GDPR consent logs are automatically deleted.')
                            ->numeric()
                            ->default(365)
                            ->required(),
                    ]),
            ]);
    }
}
