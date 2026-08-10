<?php

namespace App\Filament\Resources\ContentBlockers\Schemas;

use App\Models\ContentBlocker;
use App\Services\ContentBlockerTemplates;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ContentBlockerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('ycookies.content_blocker.information'))
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->label(__('ycookies.content_blocker.name')),
                        TextInput::make('key')
                            ->required()
                            ->label(__('ycookies.content_blocker.id'))
                            ->helperText(__('ycookies.content_blocker.id_help'))
                            ->disabled(fn (?ContentBlocker $record) => $record?->is_system),
                        Toggle::make('is_active')
                            ->label(__('ycookies.content_blocker.status'))
                            ->default(true)
                            ->hidden(fn (?ContentBlocker $record) => $record?->is_system),
                        \Filament\Forms\Components\Placeholder::make('system_status')
                            ->label(__('ycookies.content_blocker.status'))
                            ->content(new \Illuminate\Support\HtmlString('<span style="color: #22c55e; font-weight: 600;">✓ Always Active</span> <span style="color: #94a3b8; font-size: 0.85em;">(System blocker — controlled per-domain via Auto-Blocking settings)</span>'))
                            ->visible(fn (?ContentBlocker $record) => $record?->is_system),
                        TextInput::make('privacy_policy_url')
                            ->label(__('ycookies.content_blocker.privacy_url'))
                            ->url()
                            ->columnSpanFull(),
                        TagsInput::make('hosts')
                            ->label(__('ycookies.content_blocker.hostnames'))
                            ->helperText(__('ycookies.content_blocker.hostnames_help'))
                            ->columnSpanFull()
                            ->disabled(fn (?ContentBlocker $record) => $record?->is_system),
                        Select::make('domain_id')
                            ->label(__('ycookies.content_blocker.assigned_domain'))
                            ->relationship('domain', 'name')
                            ->searchable()
                            ->required(fn (?ContentBlocker $record) => ! $record?->is_system)
                            ->placeholder(fn (?ContentBlocker $record) => $record?->is_system ? 'All Domains (global)' : null)
                            ->disabled(fn (?ContentBlocker $record) => $record?->is_system)
                            ->columnSpanFull(),
                    ]),

                Section::make('Design Template')
                    ->description('Choose a predefined design or customise the HTML below.')
                    ->schema([
                        Select::make('design_template')
                            ->label('Template')
                            ->options(ContentBlockerTemplates::getOptions())
                            ->placeholder('Custom (manual)')
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set) {
                                if (! $state) {
                                    return;
                                }
                                $tpl = ContentBlockerTemplates::getTemplate($state);
                                if (! $tpl) {
                                    return;
                                }
                                $set('display_mode', $tpl['display_mode'] ?? 'inline');
                                $set('floating_position', $tpl['floating_position'] ?? null);
                                $set('html_code', $tpl['html_code'] ?? '');
                                $set('css_code', $tpl['css_code'] ?? '');
                                $set('js_code', $tpl['js_code'] ?? '');
                            })
                            ->columnSpanFull(),

                        Select::make('display_mode')
                            ->label('Display Mode')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Whether the placeholder overrides the missing iframe, or floats as a button to unblock tracking anywhere on the page.')
                            ->options([
                                'inline' => 'Inline (replaces content)',
                                'floating' => 'Floating (fixed-position widget)',
                            ])
                            ->default('inline')
                            ->live()
                            ->columnSpanFull(),

                        Select::make('floating_position')
                            ->label('Floating Position')
                            ->options([
                                'bottom-right' => 'Bottom Right',
                                'bottom-left' => 'Bottom Left',
                            ])
                            ->default('bottom-right')
                            ->visible(fn (Get $get) => $get('display_mode') === 'floating')
                            ->columnSpanFull(),

                        FileUpload::make('floating_icon_url')
                            ->label('Custom Floating Icon')
                            ->helperText('Optional. Replaces the default SVG icon.')
                            ->image()
                            ->directory('content-blockers')
                            ->visible(fn (Get $get) => $get('display_mode') === 'floating')
                            ->columnSpanFull(),

                        TextInput::make('floating_label')
                            ->label('Floating Tooltip')
                            ->helperText('Shown on hover. Defaults to the blocker name.')
                            ->visible(fn (Get $get) => $get('display_mode') === 'floating')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('ycookies.content_blocker.appearance'))
                    ->description(__('ycookies.content_blocker.appearance_desc'))
                    ->schema([
                        FileUpload::make('preview_image_url')
                            ->label(__('ycookies.content_blocker.preview_image'))
                            ->image()
                            ->directory('content-blockers')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('ycookies.content_blocker.html_css_js'))
                    ->description(__('ycookies.content_blocker.html_css_js_desc'))
                    ->schema([
                        Textarea::make('html_code')
                            ->label(__('ycookies.content_blocker.html'))
                            ->hintIcon('heroicon-m-code-bracket', tooltip: 'Raw HTML structure for the blocked widget (e.g. YouTube placeholder).')
                            ->extraAttributes(['style' => 'font-family: monospace;'])
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('css_code')
                            ->label(__('ycookies.content_blocker.css'))
                            ->hintIcon('heroicon-m-paint-brush', tooltip: 'CSS styles that will ONLY be loaded if this widget needs to be visually blocked.')
                            ->extraAttributes(['style' => 'font-family: monospace;'])
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('js_code')
                            ->label(__('ycookies.content_blocker.javascript'))
                            ->hintIcon('heroicon-m-command-line', tooltip: 'Javascript code to attach listeners (like the "Load Video" button) directly to the placeholder.')
                            ->extraAttributes(['style' => 'font-family: monospace;'])
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('ycookies.content_blocker.additional_settings'))
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('instructions')
                            ->hiddenLabel()
                            ->content(fn (\Illuminate\Database\Eloquent\Model $record) => new \Illuminate\Support\HtmlString('<div class="rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-800 dark:border-primary-900/50 dark:bg-primary-900/20 dark:text-primary-400">'.$record->instructions.'</div>'))
                            ->visible(fn (?\Illuminate\Database\Eloquent\Model $record) => !empty($record?->instructions))
                            ->columnSpanFull(),
                        KeyValue::make('text_placeholders')
                            ->label(__('ycookies.content_blocker.text_replacements'))
                            ->keyLabel(__('ycookies.content_blocker.variable_name'))
                            ->valueLabel(__('ycookies.content_blocker.replacement_text'))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('ycookies.content_blocker.service_provider'))
                    ->description(__('ycookies.content_blocker.service_provider_desc'))
                    ->schema([
                        Select::make('provider_id')
                            ->label(__('ycookies.service.provider'))
                            ->relationship('provider', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('service_id')
                            ->label(__('ycookies.content_blocker.service_context'))
                            ->relationship('service', 'name')
                            ->searchable()
                            ->preload(),
                    ]),
            ]);
    }
}
