<?php

namespace App\Filament\Resources\LanguageLines\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\KeyValue;

class LanguageLineForm
{
    use \App\Filament\Concerns\HasTranslationFields;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('group')
                    ->label(__('ycookies.common.translation_group'))
                    ->helperText(__('Type "*" for standard text translations. Use a specific name (like "messages") only for grouped keys.'))
                    ->placeholder('*')
                    ->required()
                    ->maxLength(255),
                TextInput::make('key')
                    ->label(__('ycookies.common.translation_key'))
                    ->helperText(__('Type the exact original text (e.g. "Welcome to our app") or the specific translation key.'))
                    ->placeholder('Welcome to our app')
                    ->required()
                    ->maxLength(255),
                ...static::translationFields([
                    ['name' => 'ycookies.common.translated_text', 'field' => 'text', 'type' => 'textarea', 'rows' => 2],
                ]),
            ]);
    }
}
