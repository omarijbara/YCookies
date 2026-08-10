<?php

namespace App\Filament\Concerns;

use App\Models\Language;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Group;

/**
 * Provides a reusable language dropdown switcher for translation fields
 * in Filament forms. Queries active languages from the database and
 * generates per-language field groups that toggle visibility based on
 * the selected language.
 */
trait HasTranslationFields
{
    /**
     * Build a language dropdown + per-language TextInput fields.
     *
     * @param  array<array{name: string, field: string, type?: string, rows?: int}>  $fields
     *         Each entry defines a translatable field:
     *         - name:  The human-readable label
     *         - field: The dot-notation state path prefix (e.g. 'name' → 'name.en')
     *         - type:  Optional, 'text' (default) or 'textarea'
     *         - rows:  Optional, number of rows for textarea
     * @param  string|null  $sectionTitle  Optional section title wrapping the fields
     * @return array  Filament schema components
     */
    protected static function translationFields(
        array $fields,
        ?string $sectionTitle = null,
        string $selectKey = '_translation_lang',
    ): array {
        $languages = Language::where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($languages->isEmpty()) {
            return [
                Placeholder::make('no_languages_' . $selectKey)
                    ->label('')
                    ->content(__('ycookies.common.no_active_languages'))
                    ->columnSpanFull(),
            ];
        }

        $langOptions = $languages->mapWithKeys(fn ($lang) => [
            $lang->code => $lang->name . ' (' . strtoupper($lang->code) . ')',
        ])->toArray();

        $firstLangCode = $languages->first()->code;

        $languageFieldGroups = [];

        foreach ($languages as $lang) {
            $code = $lang->code;

            $fieldComponents = [];
            foreach ($fields as $fieldDef) {
                $name = $fieldDef['name'];
                $statePath = $fieldDef['field'] . '.' . $code;
                $type = $fieldDef['type'] ?? 'text';
                $placeholder = $fieldDef['placeholder'] ?? '';

                if ($type === 'textarea') {
                    $fieldComponents[] = Textarea::make($statePath)
                        ->label(__($name))
                        ->placeholder($placeholder)
                        ->rows($fieldDef['rows'] ?? 3)
                        ->columnSpanFull();
                } else {
                    $fieldComponents[] = TextInput::make($statePath)
                        ->label(__($name))
                        ->placeholder($placeholder)
                        ->columnSpanFull();
                }
            }

            $languageFieldGroups[] = Group::make($fieldComponents)
                ->visible(fn ($get): bool => ($get($selectKey) ?? $firstLangCode) === $code)
                ->columnSpanFull();
        }

        return [
            Select::make($selectKey)
                ->label(__('ycookies.common.language'))
                ->options($langOptions)
                ->default($firstLangCode)
                ->selectablePlaceholder(false)
                ->live()
                ->dehydrated(false)
                ->native(false)
                ->columnSpanFull(),

            ...$languageFieldGroups,
        ];
    }
}
