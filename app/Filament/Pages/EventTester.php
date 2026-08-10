<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EventTester extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.tools');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.consent_debugger');
    }

    public function getTitle(): string
    {
        return __('ycookies.resources.consent_debugger');
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.consent_debugger');
    }

    protected string $view = 'filament.pages.event-tester';

    public ?array $data = [];

    public function mount(): void
    {
        $tenant = \Filament\Facades\Filament::getTenant();
        $domains = $tenant ? $tenant->domains : \App\Models\Domain::all();
        $this->form->fill([
            'debug_mode' => 'domain',
            'site_id' => $domains->first()?->site_id,
            'mode' => 'test',
            'custom_url' => '',
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Grid::make(3)
                    ->schema([
                        \Filament\Schemas\Components\Group::make([
                            Section::make('Launchpad Configuration')
                                ->description('Configure your target domain and simulation environment.')
                                ->schema([
                                    Select::make('debug_mode')
                                        ->label('Debug Mode')
                                        ->options([
                                            'domain' => '🔒 Installed Domain — Debug your YCookies integration',
                                            'url' => '🌐 External URL — Debug any website\'s tags & pixels',
                                        ])
                                        ->default('domain')
                                        ->required()
                                        ->live(),

                                    // Domain mode fields
                                    Select::make('site_id')
                                        ->label('Target Domain')
                                        ->options(function () {
                                            $tenant = \Filament\Facades\Filament::getTenant();
                                            $domains = $tenant ? $tenant->domains : \App\Models\Domain::all();

                                            return $domains->mapWithKeys(fn ($d) => [$d->site_id => $d->name.' — '.substr($d->site_id, 0, 8).'...']);
                                        })
                                        ->searchable()
                                        ->hidden(fn (Get $get) => $get('debug_mode') !== 'domain'),
                                    Select::make('mode')
                                        ->label('Environment Mode')
                                        ->options([
                                            'test' => 'Test Page (Safe Preview Environment)',
                                            'live' => 'Live Website (Production Environment)',
                                        ])
                                        ->default('test')
                                        ->live()
                                        ->hidden(fn (Get $get) => $get('debug_mode') !== 'domain'),

                                    // External URL mode fields
                                    TextInput::make('custom_url')
                                        ->label('Website URL')
                                        ->placeholder('https://example.com')
                                        ->helperText('Enter the full URL of the website you want to debug. All tracking pixels (GTM, Meta, TikTok, etc.) will be intercepted and displayed.')
                                        ->url()
                                        ->hidden(fn (Get $get) => $get('debug_mode') !== 'url'),
                                ]),

                            \Filament\Schemas\Components\View::make('filament.forms.components.launchpad-actions'),
                        ])->columnSpan(['lg' => 2]),

                        \Filament\Schemas\Components\Group::make([
                            \Filament\Schemas\Components\View::make('filament.forms.components.launchpad-info'),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ])
            ->statePath('data');
    }

    public function openDebugger(): void
    {
        $debugMode = $this->data['debug_mode'] ?? 'domain';

        if ($debugMode === 'url') {
            $customUrl = $this->data['custom_url'] ?? '';
            if (empty($customUrl)) {
                return;
            }

            $url = url('/ycookies/debugger').'?'.http_build_query([
                'url' => $customUrl,
                'mode' => 'universal',
            ]);
        } else {
            $siteId = $this->data['site_id'] ?? null;
            if (empty($siteId)) {
                return;
            }

            $mode = $this->data['mode'] ?? 'test';
            $url = url('/ycookies/debugger')."?site_id={$siteId}&mode={$mode}";
        }

        $this->dispatch('open-url-in-new-tab', url: $url);
    }
}
