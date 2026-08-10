<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\CookieBar;
use App\Models\Domain;
use App\Models\Group;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class RegisterGroup extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Create Organization & Domain';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Wizard::make([
                    Step::make('Organization')
                        ->schema([
                            TextInput::make('name')
                                ->label('Organization / Group Name')
                                ->required()
                                ->maxLength(255),
                        ]),
                    Step::make('Domain')
                        ->schema([
                            TextInput::make('domain_name')
                                ->label('Domain Name (e.g. example.com)')
                                ->required()
                                ->live(onBlur: true)
                                ->maxLength(255),
                            TextInput::make('origin_url')
                                ->label('Origin URL')
                                ->placeholder(fn (\Filament\Schemas\Components\Utilities\Get $get) => 'https://'.($get('domain_name') ?: 'www.example.com'))
                                ->required()
                                ->url()
                                ->helperText('The real server URL we should proxy traffic to.')
                                ->maxLength(255),
                        ]),
                    Step::make('Proxy & Automation')
                        ->schema([
                            Toggle::make('prepopulate_config')
                                ->label('Auto-configure Best Practice Settings')
                                ->helperText('Generate an attractive Cookie Banner, Essential/Analytics/Marketing cookie categories, and standard proxy configurations automatically.')
                                ->default(true),
                            TextInput::make('contact_email')
                                ->label('Contact / Reporting Email')
                                ->email()
                                ->helperText('We will send compliance reports and missing cookie alerts to this email.')
                                ->required()
                                ->maxLength(255),
                        ]),
                ])->submitAction(new HtmlString(Blade::render('<div class="mt-4"><x-filament::button type="submit" size="sm">Complete Registration</x-filament::button></div>'))),
            ]);
    }

    /**
     * Hide standard RegisterTenant form actions so only the Wizard's submit button is used.
     */
    protected function getFormActions(): array
    {
        return [];
    }

    protected function handleRegistration(array $data): Group
    {
        // 1. Create the Group
        $group = Group::create([
            'name' => $data['name'],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $user->groups()->attach($group->id, ['role' => 'owner']);

        // 2. Prepopulate config if requested
        $cookieBarId = null;
        if ($data['prepopulate_config'] ?? false) {
            $defaultBar = CookieBar::create([
                'name' => 'Default Cookie Banner',
                'group_id' => $group->id,
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
            ]);
            $cookieBarId = $defaultBar->id;
        }

        // 3. Provision the Domain
        $domain = Domain::create([
            'group_id' => $group->id,
            'name' => $data['domain_name'],
            'site_id' => Str::uuid()->toString(),
            'cookie_bar_id' => $cookieBarId, // attach if created
            'ui_config' => ['layout' => 'box_modal'],
            'proxy_engine' => 'node',
            'proxy_enabled' => true,
            'origin_url' => $data['origin_url'],
            'origin_ip' => null, // Let proxy resolve via dns or URL
            'report_email' => $data['contact_email'] ?? null,
            'scheduler_enabled' => true,
            'scheduler_mode' => 'traffic',
            'lock_minutes' => 60,
        ]);

        // Schedule Scan
        Artisan::queue('ycookies:scan:domain', ['domain' => $domain->id]);

        Notification::make()
            ->success()
            ->title('Organization Created')
            ->body('Your initial domain has been provisioned and the first automated scan has been queued.')
            ->send();

        return $group;
    }
}
