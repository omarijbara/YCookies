<?php

namespace App\Filament\Resources\LanguageLines;

use App\Filament\Resources\LanguageLines\Pages\CreateLanguageLine;
use App\Filament\Resources\LanguageLines\Pages\EditLanguageLine;
use App\Filament\Resources\LanguageLines\Pages\ListLanguageLines;
use App\Filament\Resources\LanguageLines\Schemas\LanguageLineForm;
use App\Filament\Resources\LanguageLines\Tables\LanguageLinesTable;
use Spatie\TranslationLoader\LanguageLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LanguageLineResource extends Resource
{
    protected static ?string $model = LanguageLine::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static ?string $recordTitleAttribute = 'key';

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.admin_translations');
    }

    public static function getModelLabel(): string
    {
        return __('ycookies.resources.translation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ycookies.resources.admin_translations');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.settings');
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function form(Schema $schema): Schema
    {
        return LanguageLineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LanguageLinesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLanguageLines::route('/'),
            'create' => CreateLanguageLine::route('/create'),
            'edit' => EditLanguageLine::route('/{record}/edit'),
        ];
    }
}
