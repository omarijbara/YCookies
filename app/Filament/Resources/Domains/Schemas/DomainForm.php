<?php

namespace App\Filament\Resources\Domains\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;

class DomainForm
{
    protected const RATE_LIMIT_EXCLUDE_PRESETS = [
        'wordpress_admin' => [
            'label' => 'WordPress Admin / Login',
            'paths' => [
                '/wp-admin*',
                '/wp-login.php*',
                '/wp-admin/admin-ajax.php*',
            ],
        ],
        'woocommerce_account' => [
            'label' => 'WooCommerce Cart / Checkout',
            'paths' => [
                '/cart*',
                '/checkout*',
                '/my-account*',
            ],
        ],
        'laravel_admin' => [
            'label' => 'Laravel Admin / Auth',
            'paths' => [
                '/admin*',
                '/login*',
                '/register*',
                '/password*',
            ],
        ],
        'filament_admin' => [
            'label' => 'Filament Admin',
            'paths' => [
                '/admin*',
                '/livewire*',
            ],
        ],
        'shopware_admin' => [
            'label' => 'Shopware Admin',
            'paths' => [
                '/admin*',
                '/api*',
            ],
        ],
        'joomla_admin' => [
            'label' => 'Joomla Administrator',
            'paths' => [
                '/administrator*',
            ],
        ],
        'drupal_admin' => [
            'label' => 'Drupal Admin / User',
            'paths' => [
                '/admin*',
                '/user*',
            ],
        ],
        'magento_admin' => [
            'label' => 'Magento Admin',
            'paths' => [
                '/admin*',
                '/customer*',
            ],
        ],
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Information')
                    ->description('Basic details about the domain and its tenant.')
                    ->schema([

                        TextInput::make('name')
                            ->required(),
                        TextInput::make('site_id')
                            ->label('Site ID')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Unique identifier used to link this domain to the consent script.')
                            ->helperText('Unique 32-character UUID used to link the JS client.')
                            ->default(fn () => (string) \Illuminate\Support\Str::uuid())
                            ->required()
                            ->unique(ignoreRecord: true),
                        Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                    ]),



                Section::make('Proxy Routing')
                    ->description(new \Illuminate\Support\HtmlString(
                        'Controls how visitor traffic reaches your origin server.<br><br><strong>How it works:</strong> Your customer points their domain\'s DNS to the YCookies server. '
                        . 'YCookies receives the traffic, fetches the real website from the <strong>origin</strong> you configure below, '
                        . 'injects the cookie banner, blocks non-consented scripts, and serves the result to visitors.'
                    ))
                    ->icon('heroicon-o-server-stack')
                    ->schema([
                        Toggle::make('proxy_enabled')
                            ->label('Enable Proxy')
                            ->helperText('When enabled, traffic for this domain will be routed through the YCookies Node proxy engine. Requires an origin (subdomain or URL) to be configured.')
                            ->default(false)
                            ->disabled(function (?\App\Models\Domain $record) {
                                if (!$record) return false;
                                return $record->group && !$record->group->canCreateDomain() && !$record->proxy_enabled;
                            })
                            ->live(),
                        TextInput::make('proxy_status')
                            ->label('Proxy Status')
                            ->disabled()
                            ->dehydrated(false)
                            ->hidden(fn (Get $get) => !$get('proxy_enabled'))
                            ->placeholder('Will be set automatically'),
                        TextInput::make('origin_subdomain')
                            ->label('Origin Subdomain')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'The real hostname of your server, hidden behind the proxy.')
                            ->placeholder('e.g., origin.yourdomain.com')
                            ->helperText(function (?\Illuminate\Database\Eloquent\Model $record) {
                                if (!$record) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<strong>🟢 Recommended for Node engine.</strong> Create a subdomain (e.g. <code>origin.example.com</code>) on the customer\'s real server that points to their server IP.<br>'
                                        . 'The Node proxy connects to this subdomain to fetch pages. This is more reliable than using a raw IP address.<br>'
                                        . '<em>Save the domain first to see Nginx/Apache setup snippets.</em>'
                                    );
                                }

                                $serverIp = config('app.proxy_public_ip', env('PROXY_PUBLIC_IP', '91.99.14.160'));
                                $siteId = $record->origin_auth_token;

                                $html = '<div class="mt-2 space-y-4 text-sm">' .
                                    '<p><strong>🟢 Recommended for Node engine.</strong> The Node proxy connects to this subdomain to fetch the real website pages.</p>' .
                                    '<p><strong>Setup steps:</strong></p>' .
                                    '<ol class="list-decimal pl-4 space-y-1">' .
                                    '<li>Create a DNS A-record (e.g. <code>origin.example.com</code>) pointing to the <strong>customer\'s real server IP</strong> (not the YCookies IP).</li>' .
                                    '<li>Install a valid SSL certificate for it.</li>' .
                                    '<li><strong>CRITICAL:</strong> Firewall the origin subdomain so it ONLY accepts traffic from the YCookies Proxy IP.</li>' .
                                    '</ol>';

                                $isLegacyValid = $record->origin_auth_token_legacy && $record->origin_auth_legacy_expires_at && $record->origin_auth_legacy_expires_at->isFuture();
                                
                                $nginxAuthCheck = '    if ($http_x_ycookies_origin_auth != "' . $siteId . '") {<br>        return 403;<br>    }';
                                $apacheAuthCheck = '    Require expr "%{HTTP:X-YCookies-Origin-Auth} == \'' . $siteId . '\'"';

                                if ($isLegacyValid) {
                                    $legacyId = $record->origin_auth_token_legacy;
                                    $nginxAuthCheck = '    if ($http_x_ycookies_origin_auth !~ "^(' . $siteId . '|' . $legacyId . ')$") {<br>        return 403;<br>    }';
                                    $apacheAuthCheck = '    Require expr "%{HTTP:X-YCookies-Origin-Auth} == \'' . $siteId . '\' || %{HTTP:X-YCookies-Origin-Auth} == \'' . $legacyId . '\'"';
                                    
                                    $html .= '<div class="mt-4 p-3 bg-warning-500/10 border border-warning-500/50 rounded-lg text-warning-400">' .
                                             '    <strong>⚠️ Secret Rotation in Progress</strong><br>' .
                                             '    The configuration below currently accepts BOTH your new and legacy secret. The legacy secret will automatically expire in ' . $record->origin_auth_legacy_expires_at->diffForHumans() . '.' .
                                             '</div>';
                                }

                                $html .= '<p class="mt-4"><strong>Nginx Server Block (Recommended):</strong></p>' .
                                    '<pre class="bg-gray-800 text-gray-100 p-2 rounded text-xs overflow-x-auto"><code>' .
                                    'server {<br>' .
                                    '    server_name origin.example.com;<br><br>' .
                                    '    allow ' . $serverIp . '; # YCookies Proxy IP<br>' .
                                    '    deny all; # Block everyone else<br><br>' .
                                    $nginxAuthCheck . '<br><br>' .
                                    '    # Normal site config below...<br>' .
                                    '}</code></pre>' .
                                    '<p><strong>Apache / .htaccess (For Shared Hosting):</strong></p>' .
                                    '<pre class="bg-gray-800 text-gray-100 p-2 rounded text-xs overflow-x-auto"><code>' .
                                    '&lt;RequireAll&gt;<br>' .
                                    '    Require ip ' . $serverIp . '<br>' .
                                    $apacheAuthCheck . '<br>' .
                                    '&lt;/RequireAll&gt;' .
                                    '</code></pre>' .
                                    '</div>';

                                return new \Illuminate\Support\HtmlString($html);
                            })
                            ->regex('/^[a-z0-9-]+(\\.?[a-z0-9-]+)+$/i')
                            ->required(fn (Get $get) => blank($get('origin_url')) && blank($get('origin_ip'))),

                        TextInput::make('origin_ip')
                            ->label('Origin IP')
                            ->placeholder('e.g., 212.227.101.145')
                            ->helperText('Legacy fallback. The Node Engine requires this for proper SNI verification if not using an origin subdomain.')
                            ->ipv4()
                            ->required(fn (Get $get) => blank($get('origin_subdomain')) && blank($get('origin_url'))),

                        TextInput::make('origin_url')
                            ->label('Origin URL')
                            ->placeholder('e.g., https://origin.example.com')
                            ->helperText(new \Illuminate\Support\HtmlString(
                                'Optional fallback if you cannot set up an Origin Subdomain. The proxy will use this URL to fetch the real website.<br>'
                                . '<em>Tip: Use the Origin Subdomain field above instead — it\'s more reliable and supports proper SSL.</em>'
                            ))
                            ->required(fn (Get $get) => blank($get('origin_subdomain')) && blank($get('origin_ip'))),
                    ]),

                Section::make('Proxy Rate Limiting')
                    ->description('Limits how many requests a single visitor can make per minute.')
                    ->icon('heroicon-o-shield-exclamation')
                    ->schema([
                        Toggle::make('rate_limit_enabled')
                            ->label('Enable Proxy Rate Limit')
                            ->helperText('Disable this only when you explicitly trust the traffic profile for this domain.')
                            ->default(true)
                            ->live(),
                        TextInput::make('rate_limit_max_requests_per_minute')
                            ->label('Requests Per Minute')
                            ->helperText('Default is 200. Use -1 for unlimited while keeping the feature enabled.')
                            ->numeric()
                            ->default(200)
                            ->minValue(-1)
                            ->required()
                            ->disabled(fn (Get $get) => !$get('rate_limit_enabled')),
                        \Filament\Forms\Components\Select::make('rate_limit_exclude_preset')
                            ->label('Add Exclusion Preset')
                            ->helperText('Choose a known CMS / framework pattern set to append into the excluded paths list.')
                            ->options(self::getRateLimitPresetOptions())
                            ->placeholder('Select a preset')
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                if (!$state || !isset(self::RATE_LIMIT_EXCLUDE_PRESETS[$state])) {
                                    return;
                                }

                                $existing = self::normalizeRateLimitLines($get('rate_limit_exclude_paths'));
                                $preset = self::RATE_LIMIT_EXCLUDE_PRESETS[$state]['paths'];
                                $merged = array_values(array_unique(array_merge($existing, $preset)));

                                $set('rate_limit_exclude_paths', implode(PHP_EOL, $merged));
                                $set('rate_limit_exclude_preset', null);
                            }),
                        Textarea::make('rate_limit_exclude_paths')
                            ->label('Excluded Paths')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'URL paths exempt from rate limiting (e.g. webhooks, APIs).')
                            ->helperText('One wildcard pattern per line. Matching paths bypass rate limiting, e.g. /wp-admin* or /admin*.')
                            ->rows(8)
                            ->placeholder("/wp-admin*\n/wp-login.php*\n/admin*")
                            ->formatStateUsing(fn ($state) => is_array($state) ? implode(PHP_EOL, $state) : $state)
                            ->dehydrateStateUsing(fn ($state) => self::normalizeRateLimitLines($state))
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Automatic Blocking (Runtime)')
                    ->description('Blocks uncategorized third-party resources until visitor consents.')
                    ->icon('heroicon-o-shield-exclamation')
                    ->schema([

                        // ── External Content (iframes/embeds) ──
                        Section::make('External Content (iframes/embeds)')
                            ->icon('heroicon-o-film')
                            ->compact()
                            ->schema([
                                \Filament\Schemas\Components\Group::make()
                                    ->statePath('auto_blocking')
                                    ->schema([
                                        Toggle::make('content')
                                            ->label('Block unknown third-party embeds')
                                            ->helperText('Blocks iframes/embeds not covered by a configured content blocker.')
                                            ->default(true)
                                            ->live(),
                                        Toggle::make('auto_create_content')
                                            ->label('Auto-create content blockers')
                                            ->helperText('Automatically create a Content Blocker for each newly discovered embed provider (under Uncategorized).')
                                            ->default(false)
                                            ->visible(fn (Get $get) => (bool) $get('content')),
                                    ]),
                                \Filament\Forms\Components\Select::make('fallback_content_blocker_id')
                                    ->label('Fallback Content Blocker')
                                    ->helperText('Layout displayed when an unknown iframe is auto-blocked. If none selected, a generic placeholder is shown.')
                                    ->options(function () {
                                        return \App\Models\ContentBlocker::query()
                                            ->where(function ($q) {
                                                $q->where('is_system', true)
                                                  ->orWhere('is_active', true);
                                            })
                                            ->pluck('name', 'id');
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->placeholder('System default (generic)')
                                    ->visible(fn (Get $get) => (bool) $get('auto_blocking.content'))
                                    ->columnSpanFull(),
                            ]),

                        // ── External Scripts ──
                        Section::make('External Scripts')
                            ->icon('heroicon-o-code-bracket')
                            ->compact()
                            ->schema([
                                \Filament\Schemas\Components\Group::make()
                                    ->statePath('auto_blocking')
                                    ->schema([
                                        Toggle::make('script')
                                            ->label('Block unknown third-party scripts')
                                            ->helperText('Blocks scripts not covered by a configured script blocker.')
                                            ->default(true)
                                            ->live(),
                                        Toggle::make('auto_create_script')
                                            ->label('Auto-create script blockers')
                                            ->helperText('Automatically create a Script Blocker for each newly discovered script provider (under Uncategorized).')
                                            ->default(false)
                                            ->visible(fn (Get $get) => (bool) $get('script')),
                                    ]),
                            ]),

                        // ── External Stylesheets ──
                        Section::make('External Stylesheets')
                            ->icon('heroicon-o-paint-brush')
                            ->compact()
                            ->schema([
                                \Filament\Schemas\Components\Group::make()
                                    ->statePath('auto_blocking')
                                    ->schema([
                                        Toggle::make('style')
                                            ->label('Block unknown third-party stylesheets')
                                            ->helperText('Blocks stylesheets loaded via <link rel="stylesheet"> from unknown third parties.')
                                            ->default(true)
                                            ->live(),
                                        Toggle::make('auto_create_style')
                                            ->label('Auto-create style blockers')
                                            ->helperText('Automatically create a Style Blocker for each newly discovered stylesheet provider (under Uncategorized).')
                                            ->default(false)
                                            ->visible(fn (Get $get) => (bool) $get('style')),
                                    ]),
                            ]),

                        // ── External Service Requests ──
                        Section::make('External Service Requests')
                            ->icon('heroicon-o-globe-alt')
                            ->compact()
                            ->schema([
                                \Filament\Schemas\Components\Group::make()
                                    ->statePath('auto_blocking')
                                    ->schema([
                                        Toggle::make('service')
                                            ->label('Block unknown third-party requests')
                                            ->helperText('Blocks fetch/XHR/beacon requests to unknown third parties until consent. Discovered services are logged but not auto-created as blockers.')
                                            ->default(true),
                                    ]),
                            ]),
                    ]),






                Section::make('Localization')
                    ->description('Configure auto-language detection, default languages, and the cookie banner switcher.')
                    ->icon('heroicon-o-language')
                    ->schema([
                        \Filament\Schemas\Components\Group::make()
                            ->statePath('localization')
                            ->schema([
                                Toggle::make('auto_detect')
                                    ->label('Enable Auto-Detect Browser Language')
                                    ->helperText('If enabled, the cookie bar will automatically detect the visitor\'s browser language and try to match it with an active language.')
                                    ->default(true),
                                \Filament\Forms\Components\Select::make('default_language')
                                    ->label('Default Language')
                                    ->helperText('The fallback language if auto-detect is disabled or if the visitor\'s language is not supported.')
                                    ->options(fn() => \App\Models\Language::where('is_active', true)->pluck('name', 'code'))
                                    ->default('en')
                                    ->required(),
                                Toggle::make('show_switcher')
                                    ->label('Show Language Switcher')
                                    ->helperText('Enable this to display a language dropdown in the cookie bar.')
                                    ->default(true),
                            ])
                            ->columns(3)
                    ]),

                Section::make('Advanced Customization')
                    ->description('Personalize the appearance and text of your consent banner.')
                    ->schema([
                        Toggle::make('cross_domain_enabled')
                            ->label('Enable Cross-Domain Consent (Premium)')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Shares a visitor\'s consent choice across all your domains.')
                            ->helperText('If enabled, user consent will be automatically synchronized across all domains within the same Tenant Group via a secure iframe hub.')
                            ->default(false),
                        \Filament\Forms\Components\Select::make('cookie_bar_id')
                            ->label('Assigned Cookie Bar Theme')
                            ->helperText('Select the visual theme and text settings for this domain.')
                            ->relationship('cookieBar', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ])->columns(2),

                Section::make('Google Consent Mode v2')
                    ->description('Enable Google Consent Mode')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        \Filament\Schemas\Components\Group::make()
                            ->statePath('tcm_config')
                            ->schema([
                                Toggle::make('enabled')
                                    ->label('Enable Consent Mode')
                                    ->helperText('Enables the Google Consent Mode v2 API for this domain.')
                                    ->default(true)
                                    ->live(),
                                Toggle::make('advanced_consent_mode')
                                    ->label('Advanced Consent Mode ⚠️')
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Forces specialized Google Tag Manager mapping strings.')
                                    ->helperText(new \Illuminate\Support\HtmlString('🚨 Cookieless fingerprinting (ad_user_data)<br>German DPAs recommend OFF unless legal review<br>+20-40% GA4 data via modeling'))
                                    ->default(false)
                                    ->hidden(fn(Get $get) => !$get('enabled')),
                                \Filament\Forms\Components\Select::make('mapping.marketing')
                                    ->label('Marketing Group Maps To')
                                    ->multiple()
                                    ->options([
                                        'ad_storage' => 'Ads',
                                        'ad_user_data' => 'User Data (Advanced ⚠️)',
                                        'ad_personalization' => 'Personalization (Advanced ⚠️)',
                                    ])
                                    ->default(['ad_storage', 'ad_user_data', 'ad_personalization'])
                                    ->hidden(fn(Get $get) => !$get('enabled')),
                                \Filament\Forms\Components\Select::make('mapping.statistics')
                                    ->label('Statistics Group Maps To')
                                    ->multiple()
                                    ->options([
                                        'analytics_storage' => 'Analytics',
                                        'functionality_storage' => 'Functionality Storage',
                                        'security_storage' => 'Security Storage',
                                    ])
                                    ->default(['analytics_storage'])
                                    ->hidden(fn(Get $get) => !$get('enabled')),
                                \Filament\Forms\Components\Repeater::make('regional_defaults')
                                    ->label('Regional Defaults (Region-Specific Consent)')
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Set different default consent states per country or region.')
                                    ->helperText('Configure specific default consent states for different regions (e.g. strict in EU, relaxed in US). Leaving a region out will fall back to the global default.')
                                    ->schema([
                                        TextInput::make('region')
                                            ->label('Region Code(s)')
                                            ->helperText('Comma-separated list (e.g. US, US-CA, DE, FR) adhering to ISO 3166-1 alpha-2 or ISO 3166-2.')
                                            ->required(),
                                        \Filament\Forms\Components\Select::make('ad_storage')
                                            ->options(['granted' => 'Granted', 'denied' => 'Denied'])
                                            ->default('denied')
                                            ->required(),
                                        \Filament\Forms\Components\Select::make('ad_user_data')
                                            ->options(['granted' => 'Granted', 'denied' => 'Denied'])
                                            ->default('denied')
                                            ->required(),
                                        \Filament\Forms\Components\Select::make('ad_personalization')
                                            ->options(['granted' => 'Granted', 'denied' => 'Denied'])
                                            ->default('denied')
                                            ->required(),
                                        \Filament\Forms\Components\Select::make('analytics_storage')
                                            ->options(['granted' => 'Granted', 'denied' => 'Denied'])
                                            ->default('denied')
                                            ->required(),
                                        \Filament\Forms\Components\Select::make('personalization_storage')
                                            ->options(['granted' => 'Granted', 'denied' => 'Denied'])
                                            ->default('denied')
                                            ->required(),
                                        \Filament\Forms\Components\Select::make('functionality_storage')
                                            ->options(['granted' => 'Granted', 'denied' => 'Denied'])
                                            ->default('denied')
                                            ->required(),
                                        \Filament\Forms\Components\Select::make('security_storage')
                                            ->options(['granted' => 'Granted', 'denied' => 'Denied'])
                                            ->default('denied')
                                            ->required(),
                                    ])
                                    ->columns(3)
                                    ->collapsible()
                                    ->defaultItems(0)
                                    ->hidden(fn(Get $get) => !$get('enabled')),
                            ])
                    ]),

                Section::make('Enterprise Options')
                    ->description('Settings for geo-targeting and forced re-consent versioning.')
                    ->icon('heroicon-o-globe-europe-africa')
                    ->schema([
                        Toggle::make('geo_restriction_eu')
                            ->label('Legacy EU Restriction')
                            ->helperText('Legacy feature: forcefully assumes non-EU visitors are opted-out or opted-in depending on regional rule. Use the specific Skipped Countries selector below instead.')
                            ->default(false),
                        \Filament\Forms\Components\Select::make('geo_skipped_countries')
                            ->label('Geo-Restriction Bypass (Skip Countries)')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Select specific countries where the cookie banner should NEVER be shown.')
                            ->helperText('Visitors from these countries will bypass the consent proxy entirely. Their page will load natively with all scripts executing immediately (e.g., to maximize tracking and conversions in unregulated regions like the US).')
                            ->multiple()
                            ->searchable()
                            ->options(self::getCountryOptions())
                            ->columnSpanFull(),
                        TextInput::make('consent_version')
                            ->label('Consent Version')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Bump this to force all visitors to re-consent.')
                            ->helperText('Increment this version number to invalidate all previous visitor consents and force them to re-accept (e.g., after significant Privacy Policy updates).')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])->columns(2),

                \Filament\Schemas\Components\Section::make('IAB TCF v2.2 Compliance')
                    ->description('Industry-standard ad-tech consent protocol. Required by some ad networks.')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        \Filament\Forms\Components\Toggle::make('tcf_config.enabled')
                            ->label('Enable IAB TCF v2.2 API')
                            ->helperText('Activating this will intercept ad-vendor strings via the standard __tcfapi window stub.')
                            ->default(false),
                        \Filament\Forms\Components\TextInput::make('tcf_config.cmp_id')
                            ->label('CMP ID')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Your registered Consent Management Platform ID from the IAB.')
                            ->numeric()
                            ->helperText('Your official CMP ID assigned by the IAB.')
                            ->hidden(fn(Get $get) => !$get('tcf_config.enabled')),
                        \Filament\Forms\Components\Repeater::make('tcf_config.purposes')
                            ->label('IAB Purposes Configuration')
                            ->schema([
                                \Filament\Forms\Components\Select::make('id')
                                    ->label('Purpose ID')
                                    ->options([
                                        1 => '1 - Store and/or access information on a device',
                                        2 => '2 - Select basic ads',
                                        3 => '3 - Create a personalised ads profile',
                                        4 => '4 - Select personalised ads',
                                        5 => '5 - Create a personalised content profile',
                                        6 => '6 - Select personalised content',
                                        7 => '7 - Measure ad performance',
                                        8 => '8 - Measure content performance',
                                        9 => '9 - Apply market research to generate audience insights',
                                        10 => '10 - Develop and improve products'
                                    ])
                                    ->required(),
                                \Filament\Forms\Components\Toggle::make('granted')
                                    ->label('Default Granted')
                                    ->default(true),
                            ])
                            ->columns(2)
                            ->defaultItems(2)
                            ->hidden(fn(Get $get) => !$get('tcf_config.enabled')),
                    ])
            ])
            ->columns(1);
    }

    protected static function getRateLimitPresetOptions(): array
    {
        $options = [];

        foreach (self::RATE_LIMIT_EXCLUDE_PRESETS as $key => $preset) {
            $options[$key] = $preset['label'];
        }

        return $options;
    }

    protected static function normalizeRateLimitLines(mixed $state): array
    {
        if (is_array($state)) {
            $lines = $state;
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string) $state) ?: [];
        }

        $normalized = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_starts_with($line, '/') && !str_starts_with($line, '*')) {
                $line = '/' . ltrim($line, '/');
            }

            $normalized[] = $line;
        }

        return array_values(array_unique($normalized));
    }

    protected static function getCountryOptions(): array
    {
        return [
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'BE' => 'Belgium',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'IE' => 'Ireland',
            'PT' => 'Portugal',
            'GR' => 'Greece',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'HR' => 'Croatia',
            'SK' => 'Slovakia',
            'SI' => 'Slovenia',
            'CY' => 'Cyprus',
            'MT' => 'Malta',
            'EE' => 'Estonia',
            'LV' => 'Latvia',
            'LT' => 'Lithuania',
            'LU' => 'Luxembourg',
            'IS' => 'Iceland',
            'LI' => 'Liechtenstein',
            'JP' => 'Japan',
            'IN' => 'India',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'CN' => 'China',
            'ZA' => 'South Africa',
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'SG' => 'Singapore',
            'NZ' => 'New Zealand',
            'IL' => 'Israel',
            'KR' => 'South Korea',
        ];
    }
}
