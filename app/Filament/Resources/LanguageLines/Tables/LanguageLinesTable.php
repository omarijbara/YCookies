<?php

namespace App\Filament\Resources\LanguageLines\Tables;

use App\Models\Language;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

class LanguageLinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('group')
                    ->label(__('ycookies.common.translation_group'))
                    ->description(fn (string $state): string => $state === '*' ? 'Standard Text' : 'Grouped Key')
                    ->badge()
                    ->color(fn (string $state): string => match(true) {
                        $state === '*' => 'success',
                        str_starts_with($state, 'ycookies') => 'primary',
                        default => 'warning',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label(__('ycookies.common.translation_key'))
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->tooltip(fn ($state) => $state),
                TextColumn::make('text')
                    ->label(__('ycookies.common.available_translations'))
                    ->searchable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder {
                        // Search through each locale's value in the JSON text column
                        // Using Laravel's -> syntax which is database-agnostic (SQLite + MySQL)
                        $locales = \App\Models\Language::where('is_active', true)->pluck('code')->toArray();
                        if (empty($locales)) {
                            $locales = ['en', 'de', 'ar', 'es'];
                        }
                        return $query->orWhere(function ($q) use ($search, $locales) {
                            foreach ($locales as $locale) {
                                $q->orWhere("text->{$locale}", 'like', "%{$search}%");
                            }
                        });
                    })
                    ->formatStateUsing(function ($state) {
                        if (!is_array($state)) return $state;
                        $formatted = [];
                        foreach ($state as $locale => $translation) {
                            $formatted[] = "<span class=\"px-2 py-1 text-xs rounded-md bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 mr-2\"><strong>" . strtoupper($locale) . ":</strong> " . e(str()->limit($translation, 30)) . "</span>";
                        }
                        return new \Illuminate\Support\HtmlString(implode(' ', $formatted));
                    }),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label(__('ycookies.common.translation_group'))
                    ->options(fn () => \Spatie\TranslationLoader\LanguageLine::query()
                        ->select('group')
                        ->distinct()
                        ->pluck('group', 'group')
                        ->toArray()
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('group');
    }
}
