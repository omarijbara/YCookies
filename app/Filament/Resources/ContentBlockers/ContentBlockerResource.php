<?php

namespace App\Filament\Resources\ContentBlockers;

use App\Filament\Resources\ContentBlockers\Pages\CreateContentBlocker;
use App\Filament\Resources\ContentBlockers\Pages\EditContentBlocker;
use App\Filament\Resources\ContentBlockers\Pages\ListContentBlockers;
use App\Filament\Resources\ContentBlockers\Schemas\ContentBlockerForm;
use App\Filament\Resources\ContentBlockers\Tables\ContentBlockersTable;
use App\Models\ContentBlocker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ContentBlockerResource extends Resource
{
    protected static ?string $model = ContentBlocker::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.blockers');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.content_blockers');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ycookies.resources.content_blockers');
    }

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ContentBlockerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContentBlockersTable::configure($table);
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
            'index' => ListContentBlockers::route('/'),
            'create' => CreateContentBlocker::route('/create'),
            'edit' => EditContentBlocker::route('/{record}/edit'),
        ];
    }
}
