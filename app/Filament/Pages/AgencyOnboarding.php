<?php

namespace App\Filament\Pages;

use App\Models\Domain;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Illuminate\Support\Facades\Artisan;

class AgencyOnboarding extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'wizard';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Workspace';
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.setup_wizard');
    }

    public function getTitle(): string
    {
        return __('ycookies.resources.setup_wizard');
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.setup_wizard');
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-rocket-launch';
    }

    protected string $view = 'filament.pages.wizard';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form
            ->schema([
                Wizard::make([
                    Wizard\Step::make('Step 1: Domain & Design')
                        ->schema([
                            TextInput::make('name')
                                ->label('Domain Name (e.g. example.com)')
                                ->required()
                                ->live(onBlur: true),
                            TextInput::make('site_id')
                                ->label('Client Site ID (UUID)')
                                ->default(\Illuminate\Support\Str::uuid()->toString())
                                ->required(),
                            Select::make('cookie_bar_id')
                                ->label('Assigned Cookie Bar Theme')
                                ->helperText('Select the visual theme and text settings for this domain.')
                                ->options(fn () => \App\Models\CookieBar::where('group_id', Filament::getTenant()?->id ?? 0)->pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->nullable(),
                            Select::make('ui_config.layout')
                                ->label('Default Layout')
                                ->options([
                                    'box_modal' => 'Centered Modal',
                                    'bar_ultraslim' => 'Ultraslim Bar',
                                    'bar_modern' => 'Modern Bar',
                                ])->default('box_modal'),

                            Section::make('DNS Setup')
                                ->description(new \Illuminate\Support\HtmlString(
                                    'Point the customer\'s domain to YCookies so the proxy can serve the cookie banner.'
                                ))
                                ->icon('heroicon-o-globe-alt')
                                ->schema([
                                    \Filament\Forms\Components\Placeholder::make('dns_instructions')
                                        ->label('')
                                        ->content(function (Get $get) {
                                            $domain = $get('name') ?: 'yourdomain.com';
                                            $serverIp = config('app.proxy_public_ip', env('PROXY_PUBLIC_IP', '91.99.14.160'));

                                            return new \Illuminate\Support\HtmlString(
                                                '<div class="p-3 rounded-lg border border-primary-500/30 bg-primary-500/5 text-sm">'
                                                .'<strong>📋 DNS Record — Add this at your domain registrar</strong>'
                                                .'<table class="mt-2 w-full text-xs"><tbody>'
                                                .'<tr><td class="pr-4 font-semibold text-gray-400">Type</td><td><code>A</code></td></tr>'
                                                .'<tr><td class="pr-4 font-semibold text-gray-400">Name / Host</td><td><code>'.e($domain).'</code></td></tr>'
                                                .'<tr><td class="pr-4 font-semibold text-gray-400">Value / Points to</td><td><code>'.$serverIp.'</code></td></tr>'
                                                .'<tr><td class="pr-4 font-semibold text-gray-400">TTL</td><td>Auto or 3600</td></tr>'
                                                .'</tbody></table>'
                                                .'<p class="mt-2 text-xs text-gray-500">SSL is auto-provisioned by Let\'s Encrypt after DNS propagation (up to 5 minutes).</p>'
                                                .'</div>'
                                            );
                                        })
                                        ->live(),
                                ]),

                            Section::make('Custom Proxy Settings')
                                ->description('Only change these if you need a specific proxy engine or custom origin configuration. Defaults work for most setups.')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->collapsed()
                                ->schema([
                                    // --- Node info box ---
                                    \Filament\Forms\Components\Placeholder::make('node_setup_guide')
                                        ->label('')
                                        ->content(function (Get $get) {
                                            $domain = $get('name') ?: 'yourdomain.com';

                                            return new \Illuminate\Support\HtmlString(
                                                '<div class="p-3 rounded-lg border border-info-500/30 bg-info-500/5 text-sm space-y-2">'
                                                .'<strong>ℹ️ Node Engine — How YCookies connects</strong>'
                                                .'<p>After the public domain points to YCookies, the proxy needs a way to reach the customer\'s real server. '
                                                .'Choose a connection method below.</p>'
                                                .'<p><strong>Recommended:</strong> use <strong>Origin IP</strong> — the customer\'s server IP. '
                                                .'YCookies will connect directly while sending the public hostname upstream.</p>'
                                                .'<p class="text-xs text-gray-500"><strong>Alternative:</strong> use an Origin Subdomain '
                                                .'(e.g. <code>origin.'.e($domain).'</code>) if you prefer a separate DNS hostname for the origin.</p>'
                                                .'</div>'
                                            );
                                        }),

                                    // --- Connection Method dropdown (UI-only, not persisted) ---
                                    Select::make('connection_method')
                                        ->label('Connection Method')
                                        ->options([
                                            'origin_ip' => 'Origin IP (recommended)',
                                            'origin_subdomain' => 'Origin Subdomain',
                                            'origin_url' => 'Origin URL',
                                        ])
                                        ->default('origin_ip')
                                        ->required()
                                        ->live()
                                        ->native(false)
                                        ->dehydrated(false)
                                        ->afterStateUpdated(function (Set $set, $state) {
                                            // Clear stale values when switching methods
                                            if ($state !== 'origin_ip') {
                                                $set('origin_ip', null);
                                            }
                                            if ($state !== 'origin_subdomain') {
                                                $set('origin_subdomain', null);
                                            }
                                            if ($state !== 'origin_url') {
                                                $set('origin_url', null);
                                            }
                                        })
                                        ->helperText('How should YCookies reach the customer\'s real server?'),

                                    // --- Origin IP field ---
                                    TextInput::make('origin_ip')
                                        ->label('Origin IP')
                                        ->placeholder('212.227.101.145')
                                        ->helperText('IP address of the customer\'s real web server.')
                                        ->regex('/^(\d{1,3}\.){3}\d{1,3}$/')
                                        ->required(fn (Get $get) => $get('connection_method') === 'origin_ip')
                                        ->hidden(fn (Get $get) => $get('connection_method') !== 'origin_ip'),

                                    // --- Origin Subdomain field (Node only) ---
                                    TextInput::make('origin_subdomain')
                                        ->label('Origin Subdomain')
                                        ->placeholder(fn (Get $get) => 'origin.'.($get('name') ?: 'yourdomain.com'))
                                        ->helperText(new \Illuminate\Support\HtmlString(
                                            'Separate hostname that points to the customer\'s real server. Must resolve to the customer\'s <strong>real server IP</strong>.'
                                        ))
                                        ->regex('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i')
                                        ->required(fn (Get $get) => $get('connection_method') === 'origin_subdomain')
                                        ->hidden(fn (Get $get) => $get('connection_method') !== 'origin_subdomain'),

                                    // --- Origin URL field ---
                                    TextInput::make('origin_url')
                                        ->label('Origin URL')
                                        ->placeholder('https://www.example.com')
                                        ->helperText('Full URL of the customer\'s real website.')
                                        ->required(fn (Get $get) => $get('connection_method') === 'origin_url')
                                        ->hidden(fn (Get $get) => $get('connection_method') !== 'origin_url'),

                                    // --- Origin Host (Advanced, Optional) ---
                                    TextInput::make('origin_host')
                                        ->label('Origin Host (Advanced, Optional)')
                                        ->placeholder(fn (Get $get) => $get('name') ?: 'yourdomain.com')
                                        ->helperText(new \Illuminate\Support\HtmlString(
                                            'Hostname YCookies sends upstream for TLS and Host routing.<br>'
                                            .'<em>If left empty, YCookies will use the public domain name.</em>'
                                        )),
                                ]),
                        ]),
                    Wizard\Step::make('Step 2: Scanner & Enterprise')
                        ->schema([
                            \Filament\Forms\Components\Toggle::make('scheduler_enabled')
                                ->label('Enable Automated Background Scan')
                                ->helperText('Automatically scan this domain to detect new cookies based on website traffic.')
                                ->live()
                                ->default(true),
                            Select::make('scheduler_mode')
                                ->label('Scan Mode')
                                ->options([
                                    'traffic' => 'Traffic-Triggered (Recommended)',
                                    'cronless' => 'Spatie Cronless PHP Loop',
                                    'webcron' => 'Web Cron',
                                ])
                                ->default('traffic')
                                ->hidden(fn (\Filament\Schemas\Components\Utilities\Get $get) => ! $get('scheduler_enabled'))
                                ->live(),
                            TextInput::make('lock_minutes')
                                ->label('Scan Interval (Minutes)')
                                ->numeric()
                                ->minValue(60)
                                ->maxValue(1440)
                                ->default(60)
                                ->hidden(fn (\Filament\Schemas\Components\Utilities\Get $get) => ! in_array($get('scheduler_mode'), ['traffic', 'cronless']) || ! $get('scheduler_enabled')),
                            \Filament\Forms\Components\Toggle::make('geo_restriction_eu')
                                ->label('Geo-Restriction (Show only in EU)')
                                ->helperText('If enabled, the cookie banner will strictly only be presented to visitors physically located in the EU via IP geolocation.')
                                ->default(false),
                            \Filament\Forms\Components\Toggle::make('cross_domain_enabled')
                                ->label('Enable Cross-Domain Consent (Premium)')
                                ->helperText('If enabled, user consent will be automatically synchronized across all domains within the same Tenant Group via a secure iframe hub.')
                                ->default(false),
                            \Filament\Forms\Components\Toggle::make('tcf_config.enabled')
                                ->label('Enable IAB TCF v2.2 API')
                                ->helperText('Activating this will structurally intercept ad-vendor strings via the standard __tcfapi window stub.')
                                ->default(false),
                        ]),
                    Wizard\Step::make('Step 3: Provision & Scan')
                        ->schema([
                            \Filament\Forms\Components\Placeholder::make('info')
                                ->content('Clicking submit will provision this domain with all advanced configurations and immediately queue the deep headless scanner.'),
                        ]),
                ])
                    ->submitAction(new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render('<x-filament::button type="submit" size="sm">Complete Setup</x-filament::button>'))),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $tenant = Filament::getTenant();

        // Enforce domain limits securely here as well
        if ($tenant && $tenant->domains()->count() >= $tenant->domain_limit) {
            Notification::make()->danger()->title('Domain limit reached')->send();

            return;
        }

        // Auto-create a default CookieBar if none selected or none exist
        $cookieBarId = $data['cookie_bar_id'] ?? null;
        if (! $cookieBarId && $tenant) {
            // Try to find any existing cookie bar for this tenant
            $existingBar = \App\Models\CookieBar::where('group_id', $tenant->id)->first();

            if ($existingBar) {
                $cookieBarId = $existingBar->id;
            } else {
                // Create a default cookie banner with sensible settings
                $defaultBar = \App\Models\CookieBar::create([
                    'name' => 'Default Cookie Banner',
                    'group_id' => $tenant->id,
                    'ui_config' => [
                        'layout' => 'box_modal',
                        'position' => 'center',
                        'trigger_mode' => 'load',
                        'colors' => [
                            'primary' => '#3b82f6',
                            'background' => '#111827',
                            'text' => '#f3f4f6',
                            'link' => '#60a5fa',
                        ],
                        'typography' => [
                            'font_family' => 'system-ui, -apple-system, sans-serif',
                            'font_size' => 15,
                        ],
                        'effects' => ['glassmorphism' => true],
                        'buttons' => [
                            'show_accept_all' => true,
                            'show_accept_essential' => false,
                            'show_settings' => true,
                            'show_save_consent' => false,
                            'show_accept_essential_only' => false,
                        ],
                    ],
                    // translations are auto-populated by the CookieBar model accessor
                    'theme_settings' => [],
                ]);
                $cookieBarId = $defaultBar->id;
            }
        }

        // Auto-enable proxy when any origin is configured
        $hasOrigin = ! empty($data['origin_ip']) || ! empty($data['origin_url']) || ! empty($data['origin_subdomain']);

        $domain = Domain::create([
            'group_id' => $tenant?->id,
            'name' => $data['name'],
            'site_id' => $data['site_id'],
            'cookie_bar_id' => $cookieBarId,
            'ui_config' => ['layout' => $data['ui_config']['layout'] ?? 'box_modal'],

            // Proxy Configuration — auto-enable when origin is set
            'proxy_engine' => 'node',
            'proxy_enabled' => $hasOrigin,
            'origin_ip' => $data['origin_ip'] ?? null,
            'origin_url' => $data['origin_url'] ?? null,
            'origin_subdomain' => $data['origin_subdomain'] ?? null,
            'origin_host' => $data['origin_host'] ?? null,

            // Scanner & Automation
            'is_auto_scan_enabled' => true,
            'scheduler_enabled' => $data['scheduler_enabled'] ?? true,
            'scheduler_mode' => $data['scheduler_mode'] ?? 'traffic',
            'lock_minutes' => max(60, $data['lock_minutes'] ?? 60),

            // Enterprise & Compliance
            'geo_restriction_eu' => $data['geo_restriction_eu'] ?? false,
            'cross_domain_enabled' => $data['cross_domain_enabled'] ?? false,
            'tcf_config' => [
                'enabled' => $data['tcf_config']['enabled'] ?? false,
                'purposes' => [
                    ['id' => 1, 'granted' => true],
                    ['id' => 2, 'granted' => true],
                ],
            ],
        ]);

        // Background Job
        Artisan::queue('ycookies:scan:domain', ['domain' => $domain->id]);

        Notification::make()->success()->title('Domain Configured! Headless scanner dispatched.')->send();
        $this->redirect(\App\Filament\Resources\Domains\DomainResource::getUrl('index', ['tenant' => $tenant]));
    }
}
