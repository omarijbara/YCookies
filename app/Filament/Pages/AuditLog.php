<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class AuditLog extends Page implements HasTable
{
    use InteractsWithTable;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.system.audit_log');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.audit_log');
    }

    protected string $view = 'filament.pages.audit-log';

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime()
                    ->sortable()
                    ->color('gray')
                    ->size('sm'),
                TextColumn::make('log_name')
                    ->label('Event Channel')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'default' => 'info',
                        'system' => 'warning',
                        'security' => 'danger',
                        default => 'primary',
                    }),
                TextColumn::make('description')
                    ->label('Action Performed')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('causer.name')
                    ->label('User / Trigger')
                    ->default('System Automated')
                    ->searchable()
                    ->icon('heroicon-m-user')
                    ->iconColor('gray'),
                TextColumn::make('subject_type')
                    ->label('Affected Entity')
                    ->formatStateUsing(fn ($state) => class_basename($state))
                    ->color('gray')
                    ->size('sm')
                    ->toggleable(),
            ])
            ->paginated([50, 100, 'all']);
    }
}
