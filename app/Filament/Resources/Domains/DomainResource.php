<?php

namespace App\Filament\Resources\Domains;

use App\Filament\Resources\Domains\Pages\CreateDomain;
use App\Filament\Resources\Domains\Pages\EditDomain;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Schemas\DomainForm;
use App\Filament\Resources\Domains\Tables\DomainsTable;
use App\Models\Domain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.consent');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.domains');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ycookies.resources.domains');
    }

    protected static ?int $navigationSort = 1;

    // Domain limit is enforced via redirect in CreateDomain::mount()
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DomainForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DomainsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\Domains\RelationManagers\CookieGroupsRelationManager::class,
            \App\Filament\Resources\Domains\RelationManagers\ServicesRelationManager::class,
            \App\Filament\Resources\Domains\RelationManagers\HealthCheckResultsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'edit' => EditDomain::route('/{record}/edit'),
        ];
    }
}
