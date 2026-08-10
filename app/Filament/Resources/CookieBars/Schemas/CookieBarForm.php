<?php

namespace App\Filament\Resources\CookieBars\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;

class CookieBarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Cookie Bar Settings')
                    ->tabs([
                        // ─── General Tab ───
                        Tab::make(__('General'))
                            ->icon('heroicon-m-cog-6-tooth')
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('Name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                Select::make('group_id')
                                    ->label(__('Group'))
                                    ->relationship('group', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->columnSpanFull(),
                                Select::make('domains')
                                    ->label(__('Attached Domains'))
                                    ->helperText(__('Select the domains where this cookie bar design should be used.'))
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->options(function () {
                                        return \App\Models\Domain::where('group_id', \Filament\Facades\Filament::getTenant()?->id ?? 0)->pluck('name', 'id');
                                    })
                                    ->loadStateFromRelationshipsUsing(function ($component, $record) {
                                        if ($record) {
                                            $component->state($record->domains->pluck('id')->toArray());
                                        }
                                    })
                                    ->saveRelationshipsUsing(function ($component, $record, $state) {
                                        \App\Models\Domain::where('cookie_bar_id', $record->id)
                                            ->whereNotIn('id', $state ?? [])
                                            ->update(['cookie_bar_id' => null]);

                                        if (!empty($state)) {
                                            \App\Models\Domain::whereIn('id', $state)
                                                ->update(['cookie_bar_id' => $record->id]);
                                        }
                                    })
                                    ->dehydrated(false)
                                    ->columnSpanFull(),
                                Select::make('ui_config.trigger_mode')
                                    ->label(__('Banner Trigger'))
                                    ->helperText(__('When should the cookie banner be displayed?'))
                                    ->options([
                                        'load' => __('Immediately on load (Default)'),
                                        'scroll' => __('On scroll'),
                                        'interaction' => __('On first interaction (scroll, click, etc.)'),
                                    ])
                                    ->default('load')
                                    ->afterStateHydrated(function (\Filament\Forms\Components\Select $component, $state) {
                                        if (blank($state)) {
                                            $component->state('load');
                                        }
                                    })
                                    ->selectablePlaceholder(false)
                                    ->required()
                                    ->live()
                                    ->columnSpanFull(),
                            ]),

                        // ─── Colors Tab ───
                        Tab::make(__('Colors'))
                            ->icon('heroicon-m-swatch')
                            ->schema([
                                Grid::make(2)->schema([
                                    ColorPicker::make('theme_settings.colors.primary')
                                        ->label(__('Primary Color'))
                                        ->default('#3b82f6')
                                        ->live(onBlur: true),
                                    ColorPicker::make('theme_settings.colors.background')
                                        ->label(__('Background'))
                                        ->default('#111827')
                                        ->live(onBlur: true),
                                    ColorPicker::make('theme_settings.colors.text')
                                        ->label(__('Text'))
                                        ->default('#f3f4f6')
                                        ->live(onBlur: true),
                                    ColorPicker::make('theme_settings.colors.link')
                                        ->label(__('Links'))
                                        ->default('#60a5fa')
                                        ->live(onBlur: true),
                                ]),
                            ]),

                        // ─── Layout Tab ───
                        Tab::make(__('Layout'))
                            ->icon('heroicon-m-rectangle-group')
                            ->schema([
                                Select::make('theme_settings.layout')
                                    ->label(__('Default Layout'))
                                    ->options([
                                        'box_modal' => __('Centered Modal (Premium)'),
                                        'box_modern_glass' => __('Modern Glass Box (Premium)'),
                                        'box_float_corner' => __('Floating Corner Card'),
                                        'box_compact' => __('Compact Box'),
                                        'bar_modern' => __('Modern Bar'),
                                        'bar_split' => __('Split Pill Bar'),
                                        'bar_ultraslim' => __('Ultraslim Bar'),
                                    ])
                                    ->default('box_modal')
                                    ->required()
                                    ->live(),
                                Select::make('theme_settings.position')
                                    ->label(__('Banner Position'))
                                    ->options([
                                        'bottom-left' => __('Bottom Left'),
                                        'bottom-right' => __('Bottom Right'),
                                        'bottom-center' => __('Bottom Center'),
                                        'center' => __('Screen Center (Modal)'),
                                        'top' => __('Top'),
                                    ])
                                    ->default('center')
                                    ->required()
                                    ->live(),
                                Toggle::make('theme_settings.effects.glassmorphism')
                                    ->label(__('Enable Glassmorphism'))
                                    ->default(true)
                                    ->live(),
                                Toggle::make('theme_settings.show_reopen_widget')
                                    ->label(__('Show Fingerprint Widget (Reopen Consent)'))
                                    ->helperText(__('Shows a floating icon allowing visitors to change their settings at any time.'))
                                    ->default(true)
                                    ->live(),
                            ]),

                        // ─── Typography Tab ───
                        Tab::make(__('Typography'))
                            ->icon('heroicon-m-language')
                            ->schema([
                                Select::make('theme_settings.typography.font_family')
                                    ->label(__('Font Family'))
                                    ->options([
                                        'system-ui, -apple-system, sans-serif' => __('System UI (Default)'),
                                        'Arial, Helvetica, sans-serif' => 'Arial',
                                        '"Segoe UI", Roboto, sans-serif' => 'Roboto/Segoe UI',
                                        'Inter, sans-serif' => 'Inter',
                                        '"Open Sans", sans-serif' => 'Open Sans',
                                        'Verdana, sans-serif' => 'Verdana',
                                    ])
                                    ->default('system-ui, -apple-system, sans-serif')
                                    ->live(),
                                TextInput::make('theme_settings.typography.font_size')
                                    ->label(__('Font Size (px)'))
                                    ->numeric()
                                    ->default(15)
                                    ->live(onBlur: true),
                            ]),

                        // ─── Buttons Tab ───
                        Tab::make(__('Buttons'))
                            ->icon('heroicon-m-hand-raised')
                            ->schema([
                                Toggle::make('theme_settings.buttons.show_accept_all')
                                    ->label(__('Show "Accept All"'))
                                    ->helperText(__('Accepts all cookie groups at once.'))
                                    ->default(true)
                                    ->live(),

                                Toggle::make('theme_settings.buttons.show_settings')
                                    ->label(__('Show "Settings / Preferences"'))
                                    ->helperText(__('Opens the detailed view of cookie groups.'))
                                    ->default(true)
                                    ->live(),
                                Toggle::make('theme_settings.buttons.show_save_consent')
                                    ->label(__('Show "Save Consent"'))
                                    ->helperText(__('Saves the currently selected (incl. pre-selected) cookie groups.'))
                                    ->default(false)
                                    ->live(),
                                Toggle::make('theme_settings.buttons.show_accept_essential_only')
                                    ->label(__('Show "Accept Essential Cookies Only"'))
                                    ->helperText(__('Rejects all optional cookies and accepts only the essential ones.'))
                                    ->default(false)
                                    ->live(),
                            ]),

                        // ─── Translations Tab ───
                        Tab::make(__('Translations'))
                            ->icon('heroicon-m-chat-bubble-bottom-center-text')
                            ->schema(static::translationsTabSchema()),

                    ])
                    ->columnSpanFull(),

                // ─── Live Preview (always visible) ───
                Section::make(__('Preview'))
                    ->icon('heroicon-m-eye')
                    ->schema([
                        View::make('filament.forms.components.cookiebar-preview')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Build the Translations tab with a language dropdown switcher.
     * Dynamically generates TextInput fields for each active language.
     */
    protected static function translationsTabSchema(): array
    {
        $languages = \App\Models\Language::where('is_active', true)
            ->orderBy('name')
            ->get();

        // If no active languages, show a placeholder message
        if ($languages->isEmpty()) {
            return [
                \Filament\Forms\Components\Placeholder::make('no_languages')
                    ->label('')
                    ->content(__('No active languages configured. Go to Languages to add and activate languages.'))
                    ->columnSpanFull(),
            ];
        }

        $langOptions = $languages->mapWithKeys(fn ($lang) => [
            $lang->code => $lang->name . ' (' . strtoupper($lang->code) . ')',
        ])->toArray();

        $firstLangCode = $languages->first()->code;

        // Build the language-specific field groups
        $languageFieldGroups = [];

        foreach ($languages as $lang) {
            $code = $lang->code;

            $languageFieldGroups[] = \Filament\Schemas\Components\Group::make([
                Section::make(__('Banner Text'))
                    ->icon('heroicon-m-document-text')
                    ->schema([
                        TextInput::make("translations.banner.title.{$code}")
                            ->label(__('Banner Title'))
                            ->placeholder(__('e.g. Cookie Settings'))
                            ->columnSpanFull(),
                        Textarea::make("translations.banner.description.{$code}")
                            ->label(__('Banner Description'))
                            ->placeholder(__('e.g. We use cookies to personalize content and ads...'))
                            ->rows(3)
                            ->columnSpanFull(),
                        TextInput::make("translations.banner.declaration_text.{$code}")
                            ->label(__('Cookie Declaration'))
                            ->placeholder(__('e.g. Cookie Declaration'))
                            ->columnSpanFull(),
                        TextInput::make("translations.banner.cross_domain_text.{$code}")
                            ->label(__('Cross-Domain Info Text'))
                            ->placeholder(__('e.g. Your consent applies to the following domains:'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Section::make(__('Button Labels'))
                    ->icon('heroicon-m-cursor-arrow-rays')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make("translations.banner.accept_all_btn.{$code}")
                                ->label(__('Accept All'))
                                ->placeholder(__('e.g. Accept All')),
                            TextInput::make("translations.banner.individual_settings_btn.{$code}")
                                ->label(__('Preferences / Settings'))
                                ->placeholder(__('e.g. Manage Preferences')),
                            TextInput::make("translations.banner.save_btn.{$code}")
                                ->label(__('Save'))
                                ->placeholder(__('e.g. Save')),
                            TextInput::make("translations.banner.save_consent_btn.{$code}")
                                ->label(__('Save Consent'))
                                ->placeholder(__('e.g. Save Consent')),
                            TextInput::make("translations.banner.accept_essential_only_btn.{$code}")
                                ->label(__('Essential Cookies Only'))
                                ->placeholder(__('e.g. Essential Only')),
                        ]),
                    ])
                    ->collapsible(),

                Section::make(__('Legal Links'))
                    ->icon('heroicon-m-scale')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make("translations.links.imprint_text.{$code}")
                                ->label(__('Imprint Text'))
                                ->placeholder(__('e.g. Imprint')),
                            TextInput::make("translations.links.imprint_url.{$code}")
                                ->label(__('Imprint URL'))
                                ->placeholder(__('e.g. /imprint'))
                                ->url(false),
                            TextInput::make("translations.links.privacy_text.{$code}")
                                ->label(__('Privacy Policy Text'))
                                ->placeholder(__('e.g. Privacy Policy')),
                            TextInput::make("translations.links.privacy_url.{$code}")
                                ->label(__('Privacy Policy URL'))
                                ->placeholder(__('e.g. /privacy'))
                                ->url(false),
                        ]),
                    ])
                    ->collapsible(),
            ])
            ->visible(fn ($get): bool => ($get('_translation_lang') ?? $firstLangCode) === $code)
            ->columnSpanFull();
        }

        return [
            Select::make('_translation_lang')
                ->label(__('Language'))
                ->options($langOptions)
                ->default($firstLangCode)
                ->selectablePlaceholder(false)
                ->live()
                ->dehydrated(false)
                ->native(false)
                ->columnSpanFull(),

            ...$languageFieldGroups,
        ];
    }
}
