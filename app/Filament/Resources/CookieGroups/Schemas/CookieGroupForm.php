<?php

namespace App\Filament\Resources\CookieGroups\Schemas;

use App\Models\CookieGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class CookieGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('translation_notice')
                    ->hiddenLabel()
                    ->content(fn () => new HtmlString(
                        '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;border-left:3px solid #f59e0b;background:rgba(245,158,11,0.08);color:#d97706;font-size:13px;">'
                        .'<span style="font-size:18px;line-height:1;">🌐</span>'
                        .'<span style="opacity:0.9;">'.__('ycookies.common.translation_notice').'</span>'
                        .'</div>'
                    ))
                    ->columnSpanFull(),

                Section::make(__('ycookies.cookie_group.usage_on_edit'))
                    ->description(__('ycookies.cookie_group.usage_on_edit_desc'))
                    ->visibleOn(Operation::Edit)
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('linked_services_summary')
                            ->label(__('ycookies.cookie_group.linked_services'))
                            ->content(function (?Model $record): HtmlString {
                                if (! $record instanceof CookieGroup) {
                                    return new HtmlString('');
                                }
                                $n = $record->services()->count();
                                $main = trans_choice('ycookies.cookie_group.services_count', $n, ['count' => $n]);
                                $hint = $n === 0
                                    ? '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">'
                                    .e(__('ycookies.cookie_group.services_none_bar_hint'))
                                    .'</p>'
                                    : '';

                                return new HtmlString(
                                    '<div><p class="text-sm text-gray-950 dark:text-white">'
                                    .e($main)
                                    .'</p>'
                                    .$hint
                                    .'</div>'
                                );
                            }),
                    ]),

                Section::make(__('ycookies.cookie_group.identity'))
                    ->columnSpanFull()
                    ->schema([
                        \Filament\Forms\Components\Select::make('group_id')
                            ->label(__('ycookies.service.master_group'))
                            ->relationship('group', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        \Filament\Forms\Components\CheckboxList::make('domains')
                            ->label(__('ycookies.service.assigned_domains'))
                            ->relationship('domains', 'name')
                            ->searchable()
                            ->columns(3)
                            ->required()
                            ->columnSpanFull(),
                        Grid::make(1)->schema([
                            TextInput::make('name')
                                ->label(__('ycookies.cookie_group.group_name'))
                                ->placeholder(__('e.g. Essential Cookies'))
                                ->required(),
                            TextInput::make('key')
                                ->required(),
                        ]),
                    ]),

                Section::make(__('ycookies.cookie_group.layout_behavior'))
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('description')
                            ->label(__('ycookies.cookie_group.description'))
                            ->placeholder(__('e.g. These cookies are necessary for the website to function...'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            TextInput::make('sort_order')
                                ->required()
                                ->numeric()
                                ->default(0),
                            Toggle::make('is_required')
                                ->helperText(__('ycookies.cookie_group.required_help'))
                                ->required(),
                            Toggle::make('is_preselected')
                                ->label(__('ycookies.cookie_group.is_preselected'))
                                ->helperText(__('ycookies.cookie_group.preselected_help'))
                                ->default(false),
                        ]),
                    ]),
            ]);
    }
}
