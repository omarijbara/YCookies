<?php

namespace App\Filament\Resources\ScriptBlockers\Schemas;

use App\Models\CookieGroup;
use App\Models\ScriptBlocker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ScriptBlockerForm
{
    /**
     * @param  string|null  $fixedBlockerType  When set (e.g. from seeds), {@see ScriptBlocker::TYPE_SCRIPT} or {@see ScriptBlocker::TYPE_STYLE} is fixed and hidden.
     */
    public static function configure(Schema $schema, ?string $fixedBlockerType = null): Schema
    {
        $typeField = $fixedBlockerType !== null
            ? [
                Hidden::make('blocker_type')
                    ->default($fixedBlockerType)
                    ->required(),
            ]
            : [
                Select::make('blocker_type')
                    ->label(__('ycookies.script_blocker.blocker_type'))
                    ->options([
                        ScriptBlocker::TYPE_SCRIPT => __('ycookies.script_blocker.type_script'),
                        ScriptBlocker::TYPE_STYLE => __('ycookies.script_blocker.type_style'),
                    ])
                    ->required()
                    ->default(ScriptBlocker::TYPE_SCRIPT)
                    ->native(false)
                    ->live(),
            ];

        return $schema
            ->components(array_merge($typeField, [
                Section::make(__('ycookies.script_blocker.section_information'))
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->label('Name')
                            ->helperText(__('ycookies.script_blocker.name_helper')),
                        TextInput::make('key')
                            ->required()
                            ->label('ID')
                            ->helperText('Unique ID for this blocker (e.g., google-analytics).')
                            ->disabled(fn (?ScriptBlocker $record) => $record?->is_system),
                        Toggle::make('is_active')
                            ->label('Status')
                            ->default(true)
                            ->hidden(fn (?ScriptBlocker $record) => $record?->is_system),
                        Placeholder::make('system_status')
                            ->label('Status')
                            ->content(new HtmlString('<span style="color: #22c55e; font-weight: 600;">✓ Always Active</span> <span style="color: #94a3b8; font-size: 0.85em;">(System blocker — controlled per-domain via Auto-Blocking settings)</span>'))
                            ->visible(fn (?ScriptBlocker $record) => $record?->is_system),
                    ])->columns(2),

                Section::make('Consent Group')
                    ->description('Which consent group must the visitor accept before this blocker releases its resources?')
                    ->schema([
                        Select::make('require_group')
                            ->label('Required Consent Group')
                            ->options(fn () => CookieGroup::query()
                                ->when(
                                    class_exists(\Filament\Facades\Filament::class) && \Filament\Facades\Filament::getTenant(),
                                    fn ($q) => $q->where('group_id', \Filament\Facades\Filament::getTenant()->getKey())
                                )
                                ->orderBy('sort_order')
                                ->pluck('name', 'key')
                                ->all())
                            ->helperText('When an unknown third-party resource is auto-blocked, it will require consent for this group.')
                            ->native(false),
                    ])
                    ->visible(fn (?ScriptBlocker $record) => $record?->is_system),

                Section::make(__('Search Terms'))
                    ->description(__('ycookies.script_blocker.search_terms_description'))
                    ->schema([
                        TagsInput::make('handles')
                            ->label('Handles')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Keywords to intercept inside script tags (e.g. google-analytics).')
                            ->helperText(__('ycookies.script_blocker.handles_helper'))
                            ->disabled(fn (?ScriptBlocker $record) => $record?->is_system),
                        TagsInput::make('phrases')
                            ->label('Phrases')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Variables or functions to block from execution.')
                            ->helperText(__('ycookies.script_blocker.phrases_helper'))
                            ->disabled(fn (?ScriptBlocker $record) => $record?->is_system),
                    ])
                    ->hidden(fn (?ScriptBlocker $record) => $record?->is_system),

                Section::make(__('Advanced Settings'))
                    ->schema([
                        Textarea::make('on_exist')
                            ->label('onExist')
                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Javascript code to evaluate right after this script executes (once unblocked).')
                            ->helperText(__('ycookies.script_blocker.on_exist_helper'))
                            ->columnSpanFull()
                            ->rows(5)
                            ->extraAttributes(['style' => 'font-family: monospace;']),
                    ])
                    ->hidden(fn (?ScriptBlocker $record) => $record?->is_system),

                Section::make(__('Service Linking'))
                    ->description(__('ycookies.script_blocker.service_description'))
                    ->schema([
                        Select::make('service_id')
                            ->relationship('service', 'name')
                            ->label('Service / Opt-in')
                            ->hintIcon('heroicon-m-shield-check', tooltip: 'Once a visitor consents to THIS service, the script will automatically unblock.')
                            ->searchable()
                            ->preload(),
                    ]),
            ]));
    }
}
