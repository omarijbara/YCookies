<?php

namespace App\Filament\Resources\Languages\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LanguageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Language Code')
                    ->placeholder('e.g. en, de, fr')
                    ->required()
                    ->maxLength(10)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Language Name')
                    ->placeholder('e.g. English, Deutsch, Français')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Uncheck to hide this language from the frontend switcher.'),
                Toggle::make('is_rtl')
                    ->label('Right-to-Left (RTL)')
                    ->default(false)
                    ->helperText('Check this if the language is read from right to left (like Arabic or Hebrew).'),
            ]);
    }
}
