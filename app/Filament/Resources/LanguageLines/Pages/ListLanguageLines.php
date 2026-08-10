<?php

namespace App\Filament\Resources\LanguageLines\Pages;

use App\Filament\Resources\LanguageLines\LanguageLineResource;
use App\Models\Language;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Spatie\TranslationLoader\LanguageLine;

class ListLanguageLines extends ListRecords
{
    protected static string $resource = LanguageLineResource::class;

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.list.language_lines');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_translations')
                ->label(__('ycookies.common.import_from_files'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('ycookies.common.import_translations'))
                ->modalDescription(__('ycookies.common.import_translations_desc'))
                ->modalSubmitActionLabel(__('ycookies.common.import'))
                ->action(function () {
                    $imported = 0;
                    $updated = 0;
                    $activeLangs = Language::where('is_active', true)->pluck('code')->toArray();

                    // Scan all ycookies.php lang files
                    $basePath = lang_path();
                    $allTranslations = [];

                    foreach ($activeLangs as $locale) {
                        $file = $basePath.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'ycookies.php';
                        if (file_exists($file)) {
                            $translations = require $file;
                            if (is_array($translations)) {
                                $flattened = $this->flattenArray($translations, 'ycookies');
                                foreach ($flattened as $key => $value) {
                                    $allTranslations[$key][$locale] = $value;
                                }
                            }
                        }
                    }

                    // Upsert into language_lines table
                    foreach ($allTranslations as $fullKey => $localeValues) {
                        $parts = explode('.', $fullKey, 2);
                        $group = $parts[0]; // 'ycookies'
                        $key = $parts[1];   // 'nav.consent_management'

                        $existing = LanguageLine::where('group', $group)
                            ->where('key', $key)
                            ->first();

                        if ($existing) {
                            $text = $existing->text;
                            $needsUpdate = false;
                            foreach ($localeValues as $locale => $value) {
                                if (! isset($text[$locale]) || $text[$locale] !== $value) {
                                    $text[$locale] = $value;
                                    $needsUpdate = true;
                                }
                            }
                            if ($needsUpdate) {
                                $existing->update(['text' => $text]);
                                $updated++;
                            }
                        } else {
                            LanguageLine::create([
                                'group' => $group,
                                'key' => $key,
                                'text' => $localeValues,
                            ]);
                            $imported++;
                        }
                    }

                    Notification::make()
                        ->success()
                        ->title(__('ycookies.common.import_complete'))
                        ->body("{$imported} new, {$updated} updated")
                        ->send();
                }),
            CreateAction::make()
                ->label(__('ycookies.common.add_translation'))
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Flatten a nested array into dot-notation keys.
     */
    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }
}
