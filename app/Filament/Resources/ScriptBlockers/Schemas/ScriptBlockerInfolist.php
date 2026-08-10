<?php

namespace App\Filament\Resources\ScriptBlockers\Schemas;

use App\Models\ScriptBlocker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ScriptBlockerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('domain.name')
                    ->label('Domain'),
                TextEntry::make('blocker_type')
                    ->label(__('ycookies.script_blocker.blocker_type'))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        ScriptBlocker::TYPE_STYLE => __('ycookies.script_blocker.type_style'),
                        default => __('ycookies.script_blocker.type_script'),
                    }),
                TextEntry::make('service.name')
                    ->label('Service')
                    ->placeholder('-'),
                TextEntry::make('key'),
                TextEntry::make('name'),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('handles')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('phrases')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('on_exist')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
