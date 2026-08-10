<?php

namespace App\Filament\Resources\CookieBars;

use App\Filament\Resources\CookieBars\Pages\CreateCookieBar;
use App\Filament\Resources\CookieBars\Pages\EditCookieBar;
use App\Filament\Resources\CookieBars\Pages\ListCookieBars;
use App\Filament\Resources\CookieBars\Schemas\CookieBarForm;
use App\Filament\Resources\CookieBars\Tables\CookieBarsTable;
use App\Models\CookieBar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CookieBarResource extends Resource
{
    protected static ?string $model = CookieBar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.consent');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.cookie_bars');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ycookies.resources.cookie_bars');
    }

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CookieBarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CookieBarsTable::configure($table);
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
            'index' => ListCookieBars::route('/'),
            'create' => CreateCookieBar::route('/create'),
            'edit' => EditCookieBar::route('/{record}/edit'),
        ];
    }
}
