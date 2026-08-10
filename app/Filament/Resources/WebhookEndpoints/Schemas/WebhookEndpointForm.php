<?php

namespace App\Filament\Resources\WebhookEndpoints\Schemas;

use App\Models\WebhookEndpoint;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WebhookEndpointForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('ycookies.webhook_endpoint.section'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('ycookies.webhook_endpoint.name'))
                            ->maxLength(255),
                        TextInput::make('url')
                            ->label(__('ycookies.webhook_endpoint.url'))
                            ->url()
                            ->required()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        TextInput::make('secret')
                            ->label(__('ycookies.webhook_endpoint.secret'))
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText(__('ycookies.webhook_endpoint.secret_help')),
                        CheckboxList::make('events')
                            ->label(__('ycookies.webhook_endpoint.events'))
                            ->options(WebhookEndpoint::eventOptions())
                            ->required()
                            ->columns(1)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label(__('ycookies.webhook_endpoint.active'))
                            ->default(true),
                    ]),
            ]);
    }
}
