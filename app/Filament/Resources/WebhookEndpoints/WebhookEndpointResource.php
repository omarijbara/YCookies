<?php

namespace App\Filament\Resources\WebhookEndpoints;

use App\Filament\Resources\WebhookEndpoints\Pages\CreateWebhookEndpoint;
use App\Filament\Resources\WebhookEndpoints\Pages\EditWebhookEndpoint;
use App\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Filament\Resources\WebhookEndpoints\Schemas\WebhookEndpointForm;
use App\Filament\Resources\WebhookEndpoints\Tables\WebhookEndpointsTable;
use App\Models\WebhookEndpoint;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.tools');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.webhook_endpoints');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ycookies.resources.webhook_endpoints');
    }

    public static function getModelLabel(): string
    {
        return __('ycookies.resources.webhook_endpoint');
    }

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return WebhookEndpointForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebhookEndpointsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEndpoints::route('/'),
            'create' => CreateWebhookEndpoint::route('/create'),
            'edit' => EditWebhookEndpoint::route('/{record}/edit'),
        ];
    }
}
