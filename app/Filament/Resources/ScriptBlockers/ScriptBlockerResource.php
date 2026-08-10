<?php

namespace App\Filament\Resources\ScriptBlockers;

use App\Filament\Resources\ScriptBlockers\Pages\CreateScriptBlocker;
use App\Filament\Resources\ScriptBlockers\Pages\EditScriptBlocker;
use App\Filament\Resources\ScriptBlockers\Pages\ListScriptBlockers;
use App\Filament\Resources\ScriptBlockers\Pages\ViewScriptBlocker;
use App\Filament\Resources\ScriptBlockers\Schemas\ScriptBlockerForm;
use App\Filament\Resources\ScriptBlockers\Schemas\ScriptBlockerInfolist;
use App\Filament\Resources\ScriptBlockers\Tables\ScriptBlockersTable;
use App\Models\ScriptBlocker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScriptBlockerResource extends Resource
{
    protected static ?string $model = ScriptBlocker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.blockers');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.script_blockers');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ycookies.resources.script_blockers');
    }

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ScriptBlockerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ScriptBlockerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScriptBlockersTable::configure($table);
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
            'index' => ListScriptBlockers::route('/'),
            'create' => CreateScriptBlocker::route('/create'),
            'view' => ViewScriptBlocker::route('/{record}'),
            'edit' => EditScriptBlocker::route('/{record}/edit'),
        ];
    }
}
