<?php

namespace App\Filament\Resources\CookieGroups;

use App\Filament\Resources\CookieGroups\Pages\CreateCookieGroup;
use App\Filament\Resources\CookieGroups\Pages\EditCookieGroup;
use App\Filament\Resources\CookieGroups\Pages\ListCookieGroups;
use App\Filament\Resources\CookieGroups\Schemas\CookieGroupForm;
use App\Filament\Resources\CookieGroups\Tables\CookieGroupsTable;
use App\Models\CookieGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CookieGroupResource extends Resource
{
    protected static ?string $model = CookieGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.service_groups');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.consent');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ycookies.resources.service_groups');
    }

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CookieGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CookieGroupsTable::configure($table);
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
            'index' => ListCookieGroups::route('/'),
            'create' => CreateCookieGroup::route('/create'),
            'edit' => EditCookieGroup::route('/{record}/edit'),
        ];
    }
}
