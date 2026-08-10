<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Forms\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Utilities\Get;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Placeholder::make('translation_notice')
                    ->hiddenLabel()
                    ->content(fn () => new HtmlString(
                        '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;border-left:3px solid #f59e0b;background:rgba(245,158,11,0.08);color:#d97706;font-size:13px;">'
                        . '<span style="font-size:18px;line-height:1;">🌐</span>'
                        . '<span style="opacity:0.9;">' . __('ycookies.common.translation_notice') . '</span>'
                        . '</div>'
                    ))
                    ->columnSpanFull(),

                Section::make(__('ycookies.service.information'))
                    ->schema([
                        Grid::make(1)->schema([
                            \Filament\Forms\Components\Select::make('group_id')
                                ->label(__('ycookies.service.master_group'))
                                ->relationship('group', 'name')
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->columnSpanFull(),
                            \Filament\Forms\Components\CheckboxList::make('domains')
                                ->label(__('ycookies.service.assigned_domains'))
                                ->relationship('domains', 'name')
                                ->searchable()
                                ->columns(2)
                                ->required(),
                            \Filament\Forms\Components\Select::make('cookie_group_id')
                                ->label(__('ycookies.service.cookie_group'))
                                ->relationship('cookieGroup', 'name')
                                ->searchable()
                                ->preload()
                                ->required(),
                            \Filament\Forms\Components\Select::make('provider_id')
                                ->label(__('ycookies.service.provider'))
                                ->relationship('provider', 'name')
                                ->searchable()
                                ->preload()
                                ->createOptionForm([
                                    TextInput::make('name')->required(),
                                    Textarea::make('address'),
                                    TextInput::make('privacy_policy_url')->url(),
                                ])
                                ->required(),
                            TextInput::make('name')
                                ->label(__('ycookies.service.service_name'))
                                ->placeholder(__('e.g. Google Analytics'))
                                ->required(),
                            TextInput::make('key')
                                ->required()
                                ->live(),
                            TextInput::make('sort_order')
                                ->required()
                                ->numeric()
                                ->default(0),
                            Toggle::make('is_active')
                                ->default(true)
                                ->required(),
                        ]),
                    ]),

                Section::make(__('ycookies.service.cookies'))
                    ->schema([
                        \Filament\Forms\Components\Repeater::make('cookies')
                            ->relationship('cookies')
                            ->schema([
                                Grid::make(1)->schema([
                                    TextInput::make('name')->required(),
                                    TextInput::make('hostname'),
                                    TextInput::make('lifetime'),
                                    TextInput::make('purpose'),
                                ])
                            ])
                            ->columnSpanFull()
                            ->defaultItems(0)
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->addActionLabel(__('ycookies.service.add_cookie')),
                        Textarea::make('purpose')
                            ->label(__('ycookies.service.purpose'))
                            ->placeholder(__('e.g. Used for analytics tracking'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('ycookies.service.additional_settings'))
                    ->schema([
                        Placeholder::make('instructions')
                            ->hiddenLabel()
                            ->content(fn (?Model $record) => new HtmlString('<div class="rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-800 dark:border-primary-900/50 dark:bg-primary-900/20 dark:text-primary-400">'.$record->instructions.'</div>'))
                            ->visible(fn (?Model $record) => !empty($record?->instructions))
                            ->columnSpanFull(),
                            
                        Grid::make(1)
                            ->relationship('settings')
                            ->schema([
                                TextInput::make('gtm_id')
                                    ->label('GTM ID')
                                    ->helperText('e.g., GTM-XXXXXXX')
                                    ->visible(fn ($get, ?\Illuminate\Database\Eloquent\Model $record) => str_contains(strtolower($record?->service?->key ?? $get('../../key') ?? ''), 'google-tag-manager') || str_contains(strtolower($record?->service?->key ?? $get('../../key') ?? ''), 'gtm')),
                                TextInput::make('ga_id')
                                    ->label('Google Analytics ID')
                                    ->helperText('e.g., G-XXXXXXX')
                                    ->visible(fn ($get, ?\Illuminate\Database\Eloquent\Model $record) => str_contains(strtolower($record?->service?->key ?? $get('../../key') ?? ''), 'google-analytics') || str_contains(strtolower($record?->service?->key ?? $get('../../key') ?? ''), 'google-ads')),
                                TextInput::make('pixel_id')
                                    ->label('Meta Pixel ID')
                                    ->helperText('e.g., 1234567890')
                                    ->visible(fn ($get, ?\Illuminate\Database\Eloquent\Model $record) => str_contains(strtolower($record?->service?->key ?? $get('../../key') ?? ''), 'meta-pixel') || str_contains(strtolower($record?->service?->key ?? $get('../../key') ?? ''), 'facebook')),
                                Toggle::make('gtm_cache_locally')
                                    ->label(__('Cache GTM Locally'))
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Downloads and serves the tracking script from your own server to bypass adblockers.')
                                    ->helperText(__('If enabled, YCookies downloads the GTM JavaScript code and serves it locally. This means GTM can be loaded before consent, as long as actual tags (e.g., Google Analytics) are configured to fire only after consent.'))
                                    ->visible(fn ($get, ?\Illuminate\Database\Eloquent\Model $record) => str_contains(strtolower($record?->service?->key ?? $get('../../key') ?? ''), 'google-tag-manager') || str_contains(strtolower($record?->service?->key ?? $get('../../key') ?? ''), 'gtm'))
                                    ->columnSpanFull(),
                                Textarea::make('opt_in_code')
                                    ->label(__('Opt-in Code (HTML/JS)'))
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'The raw HTML/JS injected based on visitor consent.')
                                    ->helperText(__('This code is injected into the DOM after the user explicitly accepts this service.'))
                                    ->rows(8)
                                    ->columnSpanFull()
                                    ->extraAttributes(['style' => 'font-family: monospace;']),
                                Textarea::make('opt_out_code')
                                    ->label(__('Opt-out Code (HTML/JS)'))
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'The raw HTML/JS injected based on visitor consent.')
                                    ->helperText(__('Optional code to execute if consent is revoked later.'))
                                    ->rows(5)
                                    ->columnSpanFull()
                                    ->extraAttributes(['style' => 'font-family: monospace;']),
                                Textarea::make('fallback_code')
                                    ->label(__('Fallback Code (HTML/JS)'))
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'The raw HTML/JS injected based on visitor consent.')
                                    ->helperText(__('Executed server-side or initially before consent is collected.'))
                                    ->rows(5)
                                    ->columnSpanFull()
                                    ->extraAttributes(['style' => 'font-family: monospace;']),
                            ])
                    ]),

                Section::make('Consent Execution')
                    ->description('Controls how this service interacts with consent signals and blocking behavior.')
                    ->collapsed()
                    ->schema([
                        \Filament\Forms\Components\Select::make('integration_type')
                            ->label('Integration Type')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'How YCookies natively handles this service (e.g. iframe blocker vs script blocker).')
                            ->options([
                                'browser_tag'        => 'Browser Tag',
                                'embed_provider'     => 'Embed Provider',
                                'script_blocker'     => 'Script Blocker',
                                'style_blocker'      => 'Style Blocker',
                                'server_destination' => 'Server Destination',
                                'functional_widget'  => 'Functional Widget',
                                'tcf_vendor_adapter' => 'TCF Vendor Adapter',
                            ])
                            ->default('browser_tag')
                            ->live()
                            ->helperText('Determines how YCookies controls this integration at runtime.'),
                        TextInput::make('provider_key')
                            ->label('Provider Key')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Internal identifier used to group services from the same tech company.')
                            ->helperText('Unique key for provider-level consent resolution (e.g. "x", "youtube", "google")'),
                        TagsInput::make('service_domains')
                            ->label('Known Domains')
                            ->helperText('Domains associated with this service (for matching and blocking)'),
                        \Filament\Forms\Components\KeyValue::make('consent_mode_mapping')
                            ->label('Consent Mode v2 Mapping')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Which Google Consent types this service satisfies.')
                            ->addActionLabel('Add Signal')
                            ->helperText('Google Consent Mode signals: consent_signals, default_state, advanced_mode'),
                        Toggle::make('supports_accept_once')
                            ->label('Supports "Load This Content"')
                            ->helperText('Show per-instance accept button on blocked embeds')
                            ->visible(fn (Get $get) => $get('integration_type') === 'embed_provider'),
                        Toggle::make('supports_accept_provider')
                            ->label('Supports "Always Allow Provider"')
                            ->helperText('Show per-provider accept button on blocked embeds')
                            ->visible(fn (Get $get) => $get('integration_type') === 'embed_provider'),
                    ]),
            ]);
    }
}
