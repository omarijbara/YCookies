<?php

namespace App\Filament\Pages;

use App\Models\AiSetting;
use App\Models\AppSetting;
use App\Models\CoolifySetting;
use App\Models\GlitchTipSetting;
use App\Models\SmtpSetting;
use App\Services\CoolifyApiService;
use App\Services\GlitchTipService;
use App\Services\NotificationService;
use App\Services\TelemetryService;
use Filament\Schemas\Components\Actions;
use Filament\Actions\Action as FormAction;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.settings';

    /**
     * Instance-wide secrets live here (SMTP, AI/Coolify/GlitchTip tokens,
     * SSH server access) — with open panel registration this page must be
     * restricted to super admins. canAccess() gates both the route and
     * navigation registration, and Livewire actions cannot run on a page
     * the user cannot mount.
     */
    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->hasRole('super_admin') ?? false);
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    public static function getNavigationLabel(): string
    {
        return 'Settings';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.settings');
    }

    public static function getNavigationSort(): ?int
    {
        return 89;
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.settings');
    }

    protected static ?string $slug = 'settings';

    public ?array $data = [];

    public function mount(): void
    {
        $smtp = SmtpSetting::instance();
        $ai = AiSetting::instance();
        $gt = GlitchTipSetting::instance();
        $coolify = CoolifySetting::instance();

        $this->form->fill([
            // Email
            'smtp_host' => $smtp->host,
            'smtp_port' => $smtp->port,
            'smtp_username' => $smtp->username,
            'smtp_password' => '',
            'smtp_encryption' => $smtp->encryption,
            'smtp_from_address' => $smtp->from_address,
            'smtp_from_name' => $smtp->from_name,
            'smtp_is_active' => $smtp->is_active,
            'smtp_notify_on_updates' => $smtp->notify_on_updates,

            // AI
            'ai_provider' => $ai->provider,
            'ai_api_key' => '',
            'ai_model' => $ai->model,
            'ai_is_active' => $ai->is_active,

            // Reporting & Telemetry
            'share_telemetry' => $ai->share_telemetry,
            'telemetry_endpoint' => $ai->telemetry_endpoint ?: 'https://improve.ypsilon.dev/api/ingest',
            'telemetry_token' => $ai->telemetry_token ?: '',

            // GlitchTip
            'gt_url' => $gt->url,
            'gt_public_url' => $gt->public_url,
            'gt_api_token' => '',
            'gt_org_slug' => $gt->org_slug,
            'gt_projects' => $gt->projects ?? [],
            'gt_is_active' => $gt->is_active,

            // Coolify
            'coolify_instance_url' => $coolify->instance_url,
            'coolify_api_token' => '',
            'coolify_is_active' => $coolify->is_active,
            'coolify_app_uuids' => $coolify->app_uuids ?? [],
            'coolify_primary_proxy_uuid' => $coolify->primary_proxy_uuid,

            // SSH Server Access
            'ssh_is_active' => $coolify->ssh_is_active,
            'ssh_host' => $coolify->ssh_host,
            'ssh_port' => $coolify->ssh_port ?? 22,
            'ssh_user' => $coolify->ssh_user ?? 'root',
            'ssh_auto_cleanup_enabled' => $coolify->ssh_auto_cleanup_enabled,
            'ssh_auto_cleanup_threshold' => $coolify->ssh_auto_cleanup_threshold ?? 80,

            // Environment
            'app_timezone' => AppSetting::instance()->timezone,
            'app_date_format' => AppSetting::instance()->date_format,
            'app_time_format' => AppSetting::instance()->time_format,
        ]);
    }

    public function getTitle(): string
    {
        return 'Settings';
    }

    // ══════════════════════════════════════════════════════════
    //  SMTP Form
    // ══════════════════════════════════════════════════════════

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('Settings')
                    ->tabs([
                        // ── Email & SMTP ──────────────────────────────────
                        Tabs\Tab::make('Email')
                            ->icon('heroicon-o-envelope')
                            ->schema([
                                Section::make('SMTP Server')
                                    ->description('Configure your outgoing mail server for sending email notifications.')
                                    ->icon('heroicon-o-server-stack')
                                    ->schema([
                                        Grid::make(3)->schema([
                                            TextInput::make('smtp_host')
                                                ->label('SMTP Host')
                                                ->placeholder('smtp.gmail.com')
                                                ->required(),
                                            TextInput::make('smtp_port')
                                                ->label('Port')
                                                ->numeric()
                                                ->default(587)
                                                ->required(),
                                            Select::make('smtp_encryption')
                                                ->label('Encryption')
                                                ->options(['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'])
                                                ->default('tls')
                                                ->required(),
                                        ]),
                                        Grid::make(2)->schema([
                                            TextInput::make('smtp_username')
                                                ->label('Username')
                                                ->placeholder('your@email.com'),
                                            TextInput::make('smtp_password')
                                                ->label('Password')
                                                ->password()
                                                ->revealable()
                                                ->placeholder('Leave empty to keep current')
                                                ->dehydrated(fn ($state) => filled($state)),
                                        ]),
                                        Actions::make([
                                            FormAction::make('test_email')
                                                ->label('Send Test Email')
                                                ->icon('heroicon-m-paper-airplane')
                                                ->color('info')
                                                ->action('sendTestEmail'),
                                        ]),
                                    ]),

                                Section::make('Sender')
                                    ->schema([
                                        Grid::make(2)->schema([
                                            TextInput::make('smtp_from_address')
                                                ->label('From Email')
                                                ->email()
                                                ->required(),
                                            TextInput::make('smtp_from_name')
                                                ->label('From Name')
                                                ->required(),
                                        ]),
                                    ]),

                                Section::make('Notifications')
                                    ->schema([
                                        Toggle::make('smtp_is_active')
                                            ->label('Enable Email Notifications')
                                            ->onColor('success'),
                                        Toggle::make('smtp_notify_on_updates')
                                            ->label('Notify on Package Updates')
                                            ->onColor('success'),
                                    ]),
                            ]),

                        // ── AI Engine ─────────────────────────────────────
                        Tabs\Tab::make('AI')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
                                Section::make('AI Provider')
                                    ->schema([
                                        Select::make('ai_provider')
                                            ->label('Provider')
                                            ->options(['openrouter' => 'OpenRouter (Recommended)'])
                                            ->required(),
                                        TextInput::make('ai_api_key')
                                            ->label('API Key')
                                            ->password()
                                            ->revealable()
                                            ->placeholder('Leave empty to keep current')
                                            ->dehydrated(fn ($state) => filled($state)),
                                        Select::make('ai_model')
                                            ->label('AI Model')
                                            ->options(AiSetting::availableModels())
                                            ->searchable()
                                            ->required(),
                                        Toggle::make('ai_is_active')
                                            ->label('Enable AI Features')
                                            ->onColor('success'),
                                        Actions::make([
                                            FormAction::make('test_ai')
                                                ->label('Test AI Connection')
                                                ->icon('heroicon-m-signal')
                                                ->color('info')
                                                ->action('testAiConnection'),
                                        ]),
                                    ]),
                            ]),

                        // ── Telemetry & Hub ──────────────────────────────
                        Tabs\Tab::make('Telemetry')
                            ->icon('heroicon-o-signal')
                            ->schema([
                                Section::make('Improve Ypsilon Hub')
                                    ->description('Configure telemetry and status reporting to the central hub.')
                                    ->schema([
                                        Toggle::make('share_telemetry')
                                            ->label('Share Anonymous Telemetry & Monitoring Data')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Allows YCookies central server to see your uptime stats to improve global proxy routing.')
                                            ->helperText('Helps improve the product by sharing anonymized health check results.')
                                            ->onColor('success'),
                                        TextInput::make('telemetry_endpoint')
                                            ->label('Hub API Endpoint')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'URL for the central Improve Ypsilon monitoring API.')
                                            ->required(),
                                        TextInput::make('telemetry_token')
                                            ->label('Instance Token')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Unique cryptographic key authenticating this specific YCookies installation.')
                                            ->disabled()
                                            ->placeholder('Not registered'),
                                        
                                        Grid::make(3)->schema([
                                            Actions::make([
                                                FormAction::make('register_hub')
                                                    ->label('Register with Hub')
                                                    ->icon('heroicon-o-arrow-up-tray')
                                                    ->color('success')
                                                    ->action('registerTelemetry'),
                                                FormAction::make('send_telemetry')
                                                    ->label('Sync Data Now')
                                                    ->icon('heroicon-o-paper-airplane')
                                                    ->color('warning')
                                                    ->action('sendTelemetryNow'),
                                            ]),
                                        ]),
                                    ]),
                            ]),

                        // ── Error Tracking ────────────────────────────────
                        Tabs\Tab::make('Errors')
                            ->icon('heroicon-o-bug-ant')
                            ->schema([
                                Section::make('GlitchTip Integration')
                                    ->schema([
                                        Toggle::make('gt_is_active')
                                            ->label('Enable Error Tracker')
                                            ->onColor('success'),
                                        TextInput::make('gt_url')
                                            ->label('Internal API URL')
                                            ->required(),
                                        TextInput::make('gt_public_url')
                                            ->label('Public Dashboard URL')
                                            ->required(),
                                        TextInput::make('gt_api_token')
                                            ->label('API Token')
                                            ->password()
                                            ->revealable()
                                            ->dehydrated(fn ($state) => filled($state)),
                                        TextInput::make('gt_org_slug')
                                            ->label('Organization Slug')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'The exact URL slug used in your GlitchTip organization settings.')
                                            ->required(),
                                        Select::make('gt_projects')
                                            ->label('Monitored Projects')
                                            ->multiple()
                                            ->searchable()
                                            ->options(function () {
                                                try {
                                                    $service = app(\App\Services\GlitchTipService::class);
                                                    if (!$service->isConfigured()) {
                                                        return [];
                                                    }
                                                    
                                                    // Ensure cache is fresh when configuring via settings
                                                    $settings = \App\Models\GlitchTipSetting::instance();
                                                    \Illuminate\Support\Facades\Cache::forget("glitchtip:projects:{$settings->org_slug}");
                                                    
                                                    $res = $service->getProjects();
                                                    
                                                    if (empty($res['projects'])) {
                                                        return [];
                                                    }
                                                    
                                                    return collect($res['projects'])->pluck('name', 'id')->toArray();
                                                } catch (\Exception $e) {
                                                    return [];
                                                }
                                            }),
                                        Actions::make([
                                            FormAction::make('test_gt')
                                                ->label('Test GlitchTip Connection')
                                                ->icon('heroicon-m-signal')
                                                ->color('info')
                                                ->action('testGlitchtipConnection'),
                                            FormAction::make('trigger_500_test')
                                                ->label('Trigger 500 Test Error')
                                                ->icon('heroicon-m-shield-exclamation')
                                                ->color('danger')
                                                ->url('/debug-sentry', shouldOpenInNewTab: true)
                                                ->tooltip('Throws a fake exception in a new tab to see if it reaches GlitchTip.'),
                                        ]),
                                    ]),
                            ]),
                        // ── Infrastructure ──────────────────────────────
                        Tabs\Tab::make('Infrastructure')
                            ->icon('heroicon-o-server-stack')
                            ->schema([
                                Section::make('Coolify Integration')
                                    ->description('Connect to your Coolify instance to monitor infrastructure proxies.')
                                    ->schema([
                                        Toggle::make('coolify_is_active')
                                            ->label('Enable Coolify Monitoring')
                                            ->onColor('success'),
                                        TextInput::make('coolify_instance_url')
                                            ->label('Coolify Base URL')
                                            ->placeholder('https://coolify.example.com')
                                            ->required(),
                                        TextInput::make('coolify_api_token')
                                            ->label('API Token')
                                            ->password()
                                            ->revealable()
                                            ->placeholder('Leave empty to keep current')
                                            ->dehydrated(fn ($state) => filled($state)),
                                        \Filament\Forms\Components\Select::make('coolify_app_uuids')
                                            ->label('Monitored Application UUIDs')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Which containers in Coolify we should pull metrics bounds from.')
                                            ->placeholder('Select YCookies proxies...')
                                            ->multiple()
                                            ->searchable()
                                            ->preload()
                                            ->options(function () {
                                                try {
                                                    $service = app(\App\Services\CoolifyApiService::class);
                                                    $result = $service->getApplications();
                                                    if (empty($result['apps'])) {
                                                        return [];
                                                    }
                                                    return collect($result['apps'])->mapWithKeys(function ($app) {
                                                        $name = $app['name'] ?? 'Unknown';
                                                        $uuid = $app['uuid'];
                                                        $status = $app['status'] ?? 'unknown';
                                                        // Capitalize status
                                                        $status = ucfirst($status);
                                                        return [$uuid => "{$name} — {$status} ({$uuid})"];
                                                    })->toArray();
                                                } catch (\Exception $e) {
                                                    return [];
                                                }
                                            })
                                            ->helperText('You must save your API Key first before this list can populate itself from Coolify.'),
                                        \Filament\Forms\Components\Select::make('coolify_primary_proxy_uuid')
                                            ->label('Primary Proxy Application')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'The single Coolify app representing the main Node proxy router.')
                                            ->placeholder('Select the main proxy app...')
                                            ->searchable()
                                            ->preload()
                                            ->options(function () {
                                                try {
                                                    $service = app(\App\Services\CoolifyApiService::class);
                                                    $result = $service->getApplications();
                                                    if (empty($result['apps'])) {
                                                        return [];
                                                    }
                                                    return collect($result['apps'])->mapWithKeys(function ($app) {
                                                        $name = $app['name'] ?? 'Unknown';
                                                        $uuid = $app['uuid'];
                                                        $status = $app['status'] ?? 'unknown';
                                                        $status = ucfirst($status);
                                                        return [$uuid => "{$name} — {$status} ({$uuid})"];
                                                    })->toArray();
                                                } catch (\Exception $e) {
                                                    return [];
                                                }
                                            })
                                            ->helperText('This app will be featured on the main dashboard Hero card.'),
                                        Actions::make([
                                            FormAction::make('test_coolify')
                                                ->label('Test Coolify Connection')
                                                ->icon('heroicon-m-signal')
                                                ->color('info')
                                                ->action('testCoolifyConnection'),
                                        ]),
                                    ]),

                                Section::make('SSH Server Access')
                                    ->description('Connect to your host server via SSH for Docker cleanup and monitoring.')
                                    ->icon('heroicon-o-key')
                                    ->schema([
                                        Toggle::make('ssh_is_active')
                                            ->label('Enable SSH Server Management')
                                            ->onColor('success')
                                            ->helperText('Allows YCookies to manage Docker resources on your host server.'),
                                        Grid::make(3)->schema([
                                            TextInput::make('ssh_host')
                                                ->label('Server Host / IP')
                                                ->placeholder('198.51.100.12')
                                                ->requiredWith('ssh_is_active'),
                                            TextInput::make('ssh_port')
                                                ->label('SSH Port')
                                                ->numeric()
                                                ->default(22),
                                            TextInput::make('ssh_user')
                                                ->label('SSH User')
                                                ->default('root')
                                                ->helperText('User with Docker access on the host.'),
                                        ]),

                                        // Key status display
                                        Placeholder::make('ssh_key_status')
                                            ->label('SSH Key')
                                            ->content(function () {
                                                $settings = CoolifySetting::instance();
                                                if (empty($settings->ssh_public_key)) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<span class="text-gray-400">No key generated yet. Click "Generate Key" below.</span>'
                                                    );
                                                }
                                                $status = match($settings->ssh_test_status) {
                                                    'ok' => '🟢 Connected',
                                                    'failed' => '🔴 Failed',
                                                    default => '⚪ Not tested',
                                                };
                                                $tested = $settings->ssh_tested_at
                                                    ? ' — last tested ' . $settings->ssh_tested_at->diffForHumans()
                                                    : '';
                                                return new \Illuminate\Support\HtmlString(
                                                    "<span class='font-medium'>{$status}{$tested}</span>"
                                                );
                                            }),

                                        // Public key display (copyable)
                                        Placeholder::make('ssh_public_key_display')
                                            ->label('Public Key (copy to your server)')
                                            ->visible(fn () => !empty(CoolifySetting::instance()->ssh_public_key))
                                            ->content(function () {
                                                $key = CoolifySetting::instance()->ssh_public_key;
                                                return new \Illuminate\Support\HtmlString(
                                                    '<div class="relative group">'
                                                    . '<code class="block p-3 rounded-lg bg-white/5 border border-white/10 text-xs font-mono text-emerald-400 break-all select-all cursor-pointer" '
                                                    . 'onclick="navigator.clipboard.writeText(this.textContent.trim()); '
                                                    . "this.nextElementSibling.classList.remove('hidden'); setTimeout(() => this.nextElementSibling.classList.add('hidden'), 2000);"
                                                    . '">' . e($key) . '</code>'
                                                    . '<span class="hidden absolute top-1 right-2 text-xs text-emerald-400 font-medium">✓ Copied!</span>'
                                                    . '</div>'
                                                    . '<p class="text-xs text-gray-500 mt-1">Click to copy, then paste on your server: <code class="text-gray-400">echo "KEY" >> ~/.ssh/authorized_keys</code></p>'
                                                );
                                            }),

                                        // Allowed commands list
                                        Placeholder::make('ssh_allowed_commands')
                                            ->label('Allowed Commands')
                                            ->visible(fn () => !empty(CoolifySetting::instance()->ssh_public_key))
                                            ->content(function () {
                                                $cmds = CoolifySetting::allowedSshCommands();
                                                $html = '<div class="flex flex-wrap gap-1.5">';
                                                foreach ($cmds as $cmd => $desc) {
                                                    $html .= '<span class="inline-flex items-center px-2 py-1 rounded-md bg-white/5 border border-white/10 text-xs font-mono text-gray-400" title="' . e($desc) . '">' . e($cmd) . '</span>';
                                                }
                                                $html .= '</div>';
                                                return new \Illuminate\Support\HtmlString($html);
                                            }),

                                        Actions::make([
                                            FormAction::make('generate_ssh_key')
                                                ->label(fn () => empty(CoolifySetting::instance()->ssh_public_key) ? 'Generate Key' : 'Regenerate Key')
                                                ->icon('heroicon-m-key')
                                                ->color('success')
                                                ->requiresConfirmation(
                                                    fn () => !empty(CoolifySetting::instance()->ssh_public_key)
                                                )
                                                ->modalHeading('Regenerate SSH Key?')
                                                ->modalDescription('This will invalidate the current key. You\'ll need to copy the new public key to your server.')
                                                ->action('generateSshKey'),
                                            FormAction::make('test_ssh')
                                                ->label('Test Connection')
                                                ->icon('heroicon-m-signal')
                                                ->color('info')
                                                ->visible(fn () => !empty(CoolifySetting::instance()->ssh_public_key))
                                                ->action('testSshConnection'),
                                            FormAction::make('remove_ssh')
                                                ->label('Remove SSH Access')
                                                ->icon('heroicon-m-trash')
                                                ->color('danger')
                                                ->visible(fn () => !empty(CoolifySetting::instance()->ssh_public_key))
                                                ->requiresConfirmation()
                                                ->modalHeading('Remove SSH Access?')
                                                ->modalDescription('This will delete the SSH key pair and disable server cleanup. You can generate a new key later.')
                                                ->action('removeSshAccess'),
                                        ]),

                                        // Setup instructions
                                        Placeholder::make('ssh_setup_guide')
                                            ->label('')
                                            ->visible(fn () => !empty(CoolifySetting::instance()->ssh_public_key))
                                            ->content(function () {
                                                return new \Illuminate\Support\HtmlString(
                                                    '<div class="rounded-xl bg-blue-500/5 border border-blue-500/20 p-4 space-y-2 mb-4">'
                                                    . '<p class="text-sm font-medium text-blue-400">SSH Setup Instructions</p>'
                                                    . '<ol class="text-xs text-gray-400 space-y-1 list-decimal list-inside">'
                                                    . '<li>Copy the public key above</li>'
                                                    . '<li>SSH into your host server</li>'
                                                    . '<li>Run: <code class="px-1 py-0.5 rounded bg-white/5 text-gray-300">echo "PASTE_KEY_HERE" >> ~/.ssh/authorized_keys</code></li>'
                                                    . '<li>Click "Test Connection" to verify</li>'
                                                    . '</ol>'
                                                    . '</div>'
                                                );
                                            }),


                                    ]),
                            ]),

                        // ── Environment Info ──────────────────────────────
                        Tabs\Tab::make('Environment')
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Section::make('Localization')
                                    ->description('Configure the display timezone and language for your dashboard.')
                                    ->icon('heroicon-o-clock')
                                    ->schema([
                                        Select::make('app_timezone')
                                            ->label('Timezone')
                                            ->options(static::getTimezoneOptions())
                                            ->searchable()
                                            ->required()
                                            ->helperText('Used for displaying scan times, health checks, and scheduled events.'),
                                        Grid::make(2)->schema([
                                            Select::make('app_date_format')
                                                ->label('Date Format')
                                                ->options([
                                                    'd.m.Y' => 'DD.MM.YYYY — ' . now()->format('d.m.Y'),
                                                    'd/m/Y' => 'DD/MM/YYYY — ' . now()->format('d/m/Y'),
                                                    'm/d/Y' => 'MM/DD/YYYY — ' . now()->format('m/d/Y'),
                                                    'Y-m-d' => 'YYYY-MM-DD — ' . now()->format('Y-m-d'),
                                                    'M d, Y' => 'Mon DD, YYYY — ' . now()->format('M d, Y'),
                                                    'd M Y' => 'DD Mon YYYY — ' . now()->format('d M Y'),
                                                ])
                                                ->required()
                                                ->native(false),
                                            Select::make('app_time_format')
                                                ->label('Time Format')
                                                ->options([
                                                    'H:i' => '24-hour — ' . now()->format('H:i'),
                                                    'h:i A' => '12-hour — ' . now()->format('h:i A'),
                                                ])
                                                ->required()
                                                ->native(false),
                                        ]),
                                        Placeholder::make('locale')
                                            ->label('Current Locale')
                                            ->content(fn () => $this->getLocales()[$this->getCurrentLocale()] ?? $this->getCurrentLocale()),
                                    ]),
                            ]),
                    ])
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // 1. SMTP
        $smtp = SmtpSetting::instance();
        $smtpUpdate = [
            'host' => $data['smtp_host'],
            'port' => $data['smtp_port'],
            'username' => $data['smtp_username'],
            'encryption' => $data['smtp_encryption'],
            'from_address' => $data['smtp_from_address'],
            'from_name' => $data['smtp_from_name'],
            'is_active' => $data['smtp_is_active'],
            'notify_on_updates' => $data['smtp_notify_on_updates'],
        ];
        if (! empty($data['smtp_password'])) {
            $smtpUpdate['password'] = $data['smtp_password'];
        }
        $smtp->update($smtpUpdate);

        // 2. AI & Telemetry
        $ai = AiSetting::instance();
        $aiUpdate = [
            'provider' => $data['ai_provider'],
            'model' => $data['ai_model'],
            'is_active' => $data['ai_is_active'],
            'share_telemetry' => $data['share_telemetry'],
            'telemetry_endpoint' => $data['telemetry_endpoint'],
        ];
        if (! empty($data['ai_api_key'])) {
            $aiUpdate['api_key'] = $data['ai_api_key'];
        }
        $ai->update($aiUpdate);

        // 3. GlitchTip
        $gt = GlitchTipSetting::instance();
        $gtUpdate = [
            'url' => $data['gt_url'],
            'public_url' => $data['gt_public_url'],
            'org_slug' => $data['gt_org_slug'],
            'projects' => $data['gt_projects'],
            'is_active' => $data['gt_is_active'],
        ];
        if (! empty($data['gt_api_token'])) {
            $gtUpdate['api_token'] = $data['gt_api_token'];
        }
        $gt->update($gtUpdate);
        app(GlitchTipService::class)->clearCache();

        // 4. Coolify
        $coolify = CoolifySetting::instance();
        $coolifyUpdate = [
            'instance_url' => $data['coolify_instance_url'],
            'app_uuids' => $data['coolify_app_uuids'],
            'primary_proxy_uuid' => $data['coolify_primary_proxy_uuid'] ?? null,
            'is_active' => $data['coolify_is_active'],
            // SSH settings
            'ssh_is_active' => $data['ssh_is_active'] ?? false,
            'ssh_host' => $data['ssh_host'] ?? null,
            'ssh_port' => $data['ssh_port'] ?? 22,
            'ssh_user' => $data['ssh_user'] ?? 'root',
            'ssh_auto_cleanup_enabled' => $data['ssh_auto_cleanup_enabled'] ?? false,
            'ssh_auto_cleanup_threshold' => $data['ssh_auto_cleanup_threshold'] ?? 80,
        ];
        if (! empty($data['coolify_api_token'])) {
            $coolifyUpdate['api_token'] = $data['coolify_api_token'];
        }
        $coolify->update($coolifyUpdate);
        app(CoolifyApiService::class)->clearCache();

        // 5. Timezone & Formats
        $appSetting = AppSetting::instance();
        $appSetting->update([
            'timezone' => $data['app_timezone'] ?? 'UTC',
            'date_format' => $data['app_date_format'] ?? 'd.m.Y',
            'time_format' => $data['app_time_format'] ?? 'H:i',
        ]);
        $appSetting->apply();

        Notification::make()
            ->success()
            ->title('Settings Saved')
            ->body('All configuration sections have been updated successfully.')
            ->send();
    }

    public function sendTestEmail(): void
    {
        // Use the data from the form if the user hasn't saved yet? 
        // Standard practice is to use the stored instance, but maybe user wants to test before saving.
        // Let's use the stored instance for safety, but notify them if it's different.
        $settings = SmtpSetting::instance();

        if (! $settings->host || ! $settings->from_address) {
            Notification::make()->danger()->title('Not Configured')->send();
            return;
        }

        try {
            NotificationService::configureDynamicMailer();
            $recipientEmail = auth()->user()?->email;

            Mail::raw(
                "YCookies SMTP Test at ".now()->format('Y-m-d H:i:s'),
                function ($message) use ($recipientEmail, $settings) {
                    $message->to($recipientEmail)
                        ->subject('✅ YCookies SMTP Test')
                        ->from($settings->from_address, $settings->from_name ?: 'YCookies');
                }
            );

            Notification::make()->success()->title('Test Email Sent!')->send();
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Email Failed')->body($e->getMessage())->send();
        }
    }

    public function testAiConnection(): void
    {
        $settings = AiSetting::instance();
        if (! $settings->isConfigured()) {
            Notification::make()->danger()->title('Not Configured')->send();
            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$settings->decrypted_api_key,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $settings->model,
                'messages' => [['role' => 'user', 'content' => 'Reply with: "YCookies AI connected!"']],
                'max_tokens' => 20,
            ]);

            if ($response->successful()) {
                Notification::make()->success()->title('✅ AI Connected')->send();
            } else {
                Notification::make()->danger()->title('Failed')->body($response->json('error.message'))->send();
            }
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
        }
    }

    public function registerTelemetry(): void
    {
        $settings = AiSetting::instance();
        $endpoint = $settings->telemetry_endpoint ?: 'https://improve.ypsilon.dev/api/ingest';
        $registerUrl = str_replace('/api/ingest', '/api/register', $endpoint);

        try {
            $response = Http::timeout(10)->post($registerUrl, [
                'instance_url' => config('app.url'),
                'version' => config('app.version', '1.0.0'),
            ]);

            if ($response->successful()) {
                $token = $response->json('instance_token');
                $settings->update(['telemetry_token' => $token]);
                
                $this->data['telemetry_token'] = $token;

                Notification::make()->success()->title('✅ Registered')->send();
            } else {
                Notification::make()->danger()->title('Failed')->send();
            }
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Error')->send();
        }
    }

    public function sendTelemetryNow(): void
    {
        $settings = AiSetting::instance();
        if (! $settings->share_telemetry || empty($settings->telemetry_token)) {
            Notification::make()->warning()->title('Configuration Required')->send();
            return;
        }

        Cache::forget('telemetry-send-lock');
        $firstUnsent = \App\Models\HealthCheckResult::whereNull('telemetry_sent_at')->first();
        
        if (! $firstUnsent) {
            Notification::make()->info()->title('No data to sync')->send();
            return;
        }

        TelemetryService::send($firstUnsent);
        Notification::make()->success()->title('📡 Synced with Hub')->send();
    }

    public function testCoolifyConnection(): void
    {
        $settings = CoolifySetting::instance();
        if (! $settings->isConfigured()) {
            Notification::make()->danger()->title('Not Configured')->send();
            return;
        }

        try {
            $result = app(CoolifyApiService::class)->testConnection();
            if ($result['ok']) {
                Notification::make()->success()->title('✅ Coolify Connected')->send();
            } else {
                Notification::make()->danger()->title('Failed')->body($result['message'])->send();
            }
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
        }
    }

    public function testGlitchtipConnection(): void
    {
        $settings = GlitchTipSetting::instance();
        if (! $settings->isConfigured()) {
            Notification::make()->danger()->title('Not Configured')->send();
            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$settings->decrypted_api_token,
                'Accept' => 'application/json',
            ])->timeout(10)->get(rtrim($settings->url, '/')."/api/0/organizations/{$settings->org_slug}/projects/");

            if ($response->successful()) {
                Notification::make()->success()->title('✅ GlitchTip Connected')->send();
            } else {
                Notification::make()->danger()->title('Failed')->send();
            }
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Error')->send();
        }
    }

    public function getEnvVariables(): array
    {
        return [
            ['key' => 'APP_ENV', 'value' => config('app.env'), 'group' => 'Application'],
            ['key' => 'APP_DEBUG', 'value' => config('app.debug') ? 'true' : 'false', 'group' => 'Application'],
            ['key' => 'APP_URL', 'value' => config('app.url'), 'group' => 'Application'],
            ['key' => 'DB_CONNECTION', 'value' => config('database.default'), 'group' => 'Database'],
            ['key' => 'CACHE_STORE', 'value' => config('cache.default'), 'group' => 'Cache'],
            ['key' => 'QUEUE_CONNECTION', 'value' => config('queue.default'), 'group' => 'Queue'],
            ['key' => 'REDIS_HOST', 'value' => config('database.redis.default.host', '—'), 'group' => 'Redis'],
        ];
    }

    public function getLocales(): array
    {
        return ['en' => 'English', 'de' => 'Deutsch', 'ar' => 'العربية'];
    }

    public function getCurrentLocale(): string
    {
        return app()->getLocale();
    }

    public static function getTimezoneOptions(): array
    {
        $regions = [
            'UTC' => 'UTC',
        ];
        foreach (timezone_identifiers_list() as $tz) {
            $parts = explode('/', $tz, 2);
            if (count($parts) === 2) {
                $city = str_replace(['_', '/'], [' ', ' / '], $parts[1]);
                $regions[$tz] = "{$parts[0]} — {$city}";
            }
        }
        return $regions;
    }



    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save All Settings')
                ->submit('save')
                ->icon('heroicon-o-check')
                ->color('primary'),
        ];
    }

    public function generateSshKey(): void
    {
        try {
            $settings = CoolifySetting::instance();
            $settings->generateSshKeyPair();
            
            // Force refresh form state
            $this->data['ssh_is_active'] = $settings->ssh_is_active;
            
            Notification::make()->success()
                ->title('SSH Key Generated')
                ->body('Please copy the public key to your server.')
                ->send();
        } catch (\Exception $e) {
            Notification::make()->danger()
                ->title('Key Generation Failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function testSshConnection(): void
    {
        // Explicitly format and save the form state to the database before testing
        // so the backend Service reads the newly typed IP address instead of null.
        $this->save();

        try {
            $service = app(\App\Services\ServerInfraService::class);
            $df = $service->getDockerDiskUsage();
            \Illuminate\Support\Facades\Log::info('SSH Test result:', $df);
            
            if (!empty($df) && isset($df['success']) && $df['success'] === true && isset($df['total'])) {
                $settings = CoolifySetting::instance();
                $settings->update([
                    'ssh_tested_at' => now(),
                    'ssh_test_status' => 'ok',
                ]);
                
                Notification::make()->success()
                    ->title('SSH Connection Successful')
                    ->body('Successfully connected and retrieved Docker stats: ' . $df['total'])
                    ->send();
            } else {
                throw new \Exception('Connection failed. Please ensure the public key is added to your server or the Docker Socket is securely mounted.');
            }
        } catch (\Exception $e) {
            $settings = CoolifySetting::instance();
            $settings->update([
                'ssh_tested_at' => now(),
                'ssh_test_status' => 'failed',
            ]);
            
            Notification::make()->danger()
                ->title('SSH Connection Failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function removeSshAccess(): void
    {
        try {
            $settings = CoolifySetting::instance();
            $settings->removeSshAccess();
            
            $this->data['ssh_is_active'] = false;
            $this->data['ssh_host'] = null;
            $this->data['ssh_port'] = 22;
            $this->data['ssh_user'] = 'root';
            
            Notification::make()->success()
                ->title('SSH Access Removed')
                ->body('The keys have been securely deleted.')
                ->send();
        } catch (\Exception $e) {
            Notification::make()->danger()
                ->title('Error Removing SSH Access')
                ->body($e->getMessage())
                ->send();
        }
    }
}
