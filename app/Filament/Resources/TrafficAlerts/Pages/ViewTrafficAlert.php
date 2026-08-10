<?php

namespace App\Filament\Resources\TrafficAlerts\Pages;

use App\Filament\Resources\TrafficAlerts\TrafficAlertResource;
use App\Models\TrafficAlertState;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewTrafficAlert extends ViewRecord
{
    protected static string $resource = TrafficAlertResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // ── Alert Overview ─────────────────────────
                Section::make('Alert Details')
                    ->icon('heroicon-o-bell-alert')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('domain.name')
                            ->label('Domain'),

                        TextEntry::make('alert_type')
                            ->label('Type')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'high_latency'           => 'warning',
                                'high_5xx_rate'          => 'danger',
                                'injection_failure'      => 'info',
                                'latency_degradation'    => 'warning',
                                'error_rate_degradation' => 'danger',
                                default                  => 'gray',
                            }),

                        TextEntry::make('severity')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'critical' => 'danger',
                                'warning'  => 'warning',
                                'info'     => 'info',
                                default    => 'gray',
                            }),

                        TextEntry::make('state')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'open'       => 'danger',
                                'suppressed' => 'warning',
                                'resolved'   => 'success',
                                default      => 'gray',
                            }),

                        TextEntry::make('hit_count')
                            ->label('Total Hits'),

                        TextEntry::make('latest_value')
                            ->label('Latest Value')
                            ->numeric(decimalPlaces: 4),
                    ]),

                // ── Timing ─────────────────────────────────
                Section::make('Timeline')
                    ->icon('heroicon-o-clock')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('first_fired_at')
                            ->label('First Seen')
                            ->dateTime()
                            ->since(),

                        TextEntry::make('last_fired_at')
                            ->label('Last Seen')
                            ->dateTime()
                            ->since(),

                        TextEntry::make('suppressed_until')
                            ->label('Suppressed Until')
                            ->dateTime()
                            ->placeholder('Not suppressed'),

                        TextEntry::make('resolved_at')
                            ->label('Resolved At')
                            ->dateTime()
                            ->placeholder('Not resolved'),
                    ]),

                // ── Latest Message ─────────────────────────
                Section::make('Latest Alert Message')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->schema([
                        TextEntry::make('latest_message')
                            ->label('')
                            ->markdown()
                            ->columnSpanFull(),
                    ]),

                // ── Evidence Payload ───────────────────────
                Section::make('Evidence')
                    ->icon('heroicon-o-chart-bar')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('evidence_payload.total_requests')
                            ->label('Total Requests')
                            ->numeric()
                            ->placeholder('—'),

                        TextEntry::make('evidence_payload.p95_ms')
                            ->label('p95 Latency')
                            ->suffix(' ms')
                            ->numeric()
                            ->placeholder('—'),

                        TextEntry::make('evidence_payload.p99_ms')
                            ->label('p99 Latency')
                            ->suffix(' ms')
                            ->numeric()
                            ->placeholder('—'),

                        TextEntry::make('evidence_payload.5xx_rate')
                            ->label('5xx Error Rate')
                            ->formatStateUsing(fn ($state) => $state !== null ? round($state * 100, 2) . '%' : '—'),

                        TextEntry::make('evidence_payload.inject_fail_rate')
                            ->label('Injection Fail Rate')
                            ->formatStateUsing(fn ($state) => $state !== null ? round($state * 100, 2) . '%' : '—'),

                        TextEntry::make('evidence_payload.cache_hit_rate')
                            ->label('Cache Hit Rate')
                            ->formatStateUsing(fn ($state) => $state !== null ? round($state * 100, 1) . '%' : '—'),

                        TextEntry::make('evidence_payload.4xx_count')
                            ->label('4xx Count')
                            ->numeric()
                            ->placeholder('—'),

                        TextEntry::make('evidence_payload.window_minutes')
                            ->label('Analysis Window')
                            ->suffix(' min')
                            ->placeholder('—'),
                    ])
                    ->collapsible(),

                // ── Action Log ─────────────────────────────
                Section::make('Operator Actions')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        RepeatableEntry::make('actionLogs')
                            ->label('')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        TextEntry::make('action')
                                            ->badge()
                                            ->color(fn (string $state) => match ($state) {
                                                'acknowledge' => 'info',
                                                'snooze'      => 'warning',
                                                'resolve'     => 'success',
                                                'reopen'      => 'danger',
                                                'note'        => 'gray',
                                                default       => 'gray',
                                            }),

                                        TextEntry::make('user.name')
                                            ->label('Operator')
                                            ->placeholder('System'),

                                        TextEntry::make('note')
                                            ->placeholder('—'),

                                        TextEntry::make('created_at')
                                            ->label('When')
                                            ->since(),
                                    ]),
                            ]),
                    ])
                    ->collapsible(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('acknowledge')
                ->label('Acknowledge')
                ->icon('heroicon-o-check')
                ->color('info')
                ->form([
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $this->record->acknowledge(auth()->id(), $data['note'] ?? null);
                    $this->refreshFormData(['actionLogs']);
                })
                ->visible(fn () => $this->record->state === 'open'),

            Action::make('snooze')
                ->label('Snooze')
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->form([
                    TextInput::make('minutes')
                        ->label('Minutes')
                        ->numeric()
                        ->default(30)
                        ->minValue(5)
                        ->maxValue(1440)
                        ->required(),
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $this->record->snooze((int) $data['minutes'], auth()->id(), $data['note'] ?? null);
                    $this->refreshFormData(['actionLogs']);
                })
                ->visible(fn () => in_array($this->record->state, ['open', 'suppressed'])),

            Action::make('resolve')
                ->label('Resolve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->form([
                    Textarea::make('note')
                        ->label('Note (optional)')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $this->record->manualResolve(auth()->id(), $data['note'] ?? null);
                    $this->refreshFormData(['actionLogs']);
                })
                ->visible(fn () => $this->record->state !== 'resolved'),

            Action::make('reopen')
                ->label('Reopen')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->reopen(auth()->id());
                    $this->refreshFormData(['actionLogs']);
                })
                ->visible(fn () => $this->record->state === 'resolved'),

            Action::make('addNote')
                ->label('Add Note')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->form([
                    Textarea::make('note')
                        ->label('Note')
                        ->rows(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->record->addNote($data['note'], auth()->id());
                    $this->refreshFormData(['actionLogs']);
                }),
        ];
    }
}
