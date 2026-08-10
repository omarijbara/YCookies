<?php

namespace App\Filament\Concerns;

/**
 * Adds locale-aware form data handling for models using Spatie\Translatable\HasTranslations.
 *
 * When the admin switches language via the header, translatable fields
 * (name, description, purpose, etc.) will load/save for that locale only,
 * preserving other locales' data.
 *
 * Usage:
 *   1. Add `use HasTranslatableContent;` to your Edit/Create page
 *   2. Define `protected array $translatableAttributes = ['name', 'description'];`
 *      Or leave empty to auto-detect from the model's $translatable property.
 */
trait HasTranslatableContent
{
    /**
     * Override in your page class to specify which attributes are translatable.
     * If left empty, will auto-detect from the model's $translatable property.
     */
    protected array $translatableAttributes = [];

    /**
     * Get the list of translatable attributes for this record.
     */
    protected function getTranslatableAttributes(): array
    {
        if (!empty($this->translatableAttributes)) {
            return $this->translatableAttributes;
        }

        $model = $this->getRecord ?? null;
        if (!$model && method_exists(static::$resource ?? '', 'getModel')) {
            $modelClass = static::$resource::getModel();
            $model = new $modelClass();
        }

        if ($model && property_exists($model, 'translatable')) {
            return $model->translatable;
        }

        return [];
    }

    /**
     * On fill: extract only the current locale's value for each translatable field.
     * This makes the form show a simple string instead of a JSON array.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $locale = app()->getLocale();
        $attributes = $this->getTranslatableAttributes();

        foreach ($attributes as $attr) {
            if (isset($data[$attr]) && is_array($data[$attr])) {
                $data[$attr] = $data[$attr][$locale] ?? '';
            }
        }

        return $data;
    }

    /**
     * On save: merge the current locale's value back into the JSON structure,
     * preserving translations for other locales.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $locale = app()->getLocale();
        $attributes = $this->getTranslatableAttributes();

        foreach ($attributes as $attr) {
            if (array_key_exists($attr, $data)) {
                $existing = [];

                // Get existing translations from the record
                if ($this->record && method_exists($this->record, 'getTranslations')) {
                    $existing = $this->record->getTranslations($attr);
                }

                // Set the current locale's value
                $existing[$locale] = $data[$attr] ?? '';

                $data[$attr] = $existing;
            }
        }

        return $data;
    }
}
