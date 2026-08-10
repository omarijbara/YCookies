<?php

namespace App\Filament\Resources\TrafficAlerts\Tables;

use App\Models\TrafficAlertState;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TrafficAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_fired_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('domain.name')
                    ->label('Domain')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('alert_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'high_latency'           => 'warning',
                        'high_5xx_rate'          => 'danger',
                        'injection_failure'      => 'info',
                        'latency_degradation'    => 'warning',
                        'error_rate_degradation' => 'danger',
                        default                  => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'critical' => 'danger',
                        'warning'  => 'warning',
                        'info'     => 'info',
                        default    => 'gray',
                    })
                    ->icon(fn (string $state) => match ($state) {
                        'critical' => 'heroicon-o-fire',
                        'warning'  => 'heroicon-o-exclamation-triangle',
                        'info'     => 'heroicon-o-information-circle',
                        default    => 'heroicon-o-minus-circle',
                    })
                    ->sortable(),

                TextColumn::make('state')
                    ->label('Status')
                    ->extraHeaderAttributes(['title' => 'Whether the alert condition is currently actively alarming or has naturally resolved.'])
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'open'       => 'danger',
                        'suppressed' => 'warning',
                        'resolved'   => 'success',
                        default      => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('latest_message')
                    ->label('Message')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(),

                TextColumn::make('hit_count')
                    ->label('Hits')
                    ->extraHeaderAttributes(['title' => 'How many times this alert condition was triggered.'])
                    ->numeric()
                    ->sortable(),

                TextColumn::make('latest_value')
                    ->label('Value')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('first_fired_at')
                    ->label('First Seen')
                    ->dateTime('M j, H:i')
                    ->sortable(),

                TextColumn::make('last_fired_at')
                    ->label('Last Seen')
                    ->since()
                    ->sortable(),

                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->since()
                    ->sortable()
                    ->placeholder('—'),

                // Duration: how long the alert was active
                TextColumn::make('duration')
                    ->label('Duration')
                    ->extraHeaderAttributes(['title' => 'How long the alert has been active since it first fired.'])
                    ->getStateUsing(function (TrafficAlertState $record) {
                        $end = $record->resolved_at ?? now();
                        if (! $record->first_fired_at) return '—';
                        return $record->first_fired_at->diffForHumans($end, true);
                    })
                    ->toggleable(),

                TextColumn::make('suppressed_until')
                    ->label('Suppressed Until')
                    ->extraHeaderAttributes(['title' => 'Alert is silenced until this time. It won\'t notify again before then.'])
                    ->dateTime('M j, H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options([
                        'open'       => 'Open',
                        'suppressed' => 'Suppressed',
                        'resolved'   => 'Resolved',
                    ]),

                SelectFilter::make('severity')
                    ->options([
                        'critical' => 'Critical',
                        'warning'  => 'Warning',
                        'info'     => 'Info',
                    ]),

                SelectFilter::make('alert_type')
                    ->options([
                        'high_latency'           => 'High Latency',
                        'high_5xx_rate'          => 'High 5xx Rate',
                        'injection_failure'      => 'Injection Failure',
                        'latency_degradation'    => 'Latency Degradation',
                        'error_rate_degradation' => 'Error Rate Degradation',
                    ]),

                SelectFilter::make('domain_id')
                    ->label('Domain')
                    ->relationship('domain', 'name'),
            ])
            ->recordActions([
                // ── View: rich modal with all details ──────
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (TrafficAlertState $record) => $record->alert_type . ' — ' . ($record->domain->name ?? 'Unknown'))
                    ->modalWidth('4xl')
                    ->infolist([
                        // Alert Details
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

                        // Message
                        Section::make('Alert Message')
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->schema([
                                TextEntry::make('latest_message')
                                    ->label('')
                                    ->columnSpanFull(),
                            ]),

                        // Timeline
                        Section::make('Timeline')
                            ->icon('heroicon-o-clock')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('first_fired_at')
                                    ->label('First Seen')
                                    ->dateTime(),
                                TextEntry::make('last_fired_at')
                                    ->label('Last Seen')
                                    ->dateTime(),
                                TextEntry::make('resolved_at')
                                    ->label('Resolved At')
                                    ->dateTime()
                                    ->placeholder('Not resolved'),
                                TextEntry::make('duration')
                                    ->label('Duration')
                                    ->getStateUsing(function (TrafficAlertState $record) {
                                        $end = $record->resolved_at ?? now();
                                        if (! $record->first_fired_at) return '—';
                                        return $record->first_fired_at->diffForHumans($end, true);
                                    }),
                                TextEntry::make('suppressed_until')
                                    ->label('Suppressed Until')
                                    ->dateTime()
                                    ->placeholder('Not suppressed'),
                            ]),

                        // Evidence
                        Section::make('Evidence Payload')
                            ->description('Raw metrics snapshot captured when the alert fired.')
                            ->icon('heroicon-o-chart-bar')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('evidence_payload.total_requests')
                                    ->label('Total Requests')
                                    ->numeric()
                                    ->placeholder('—'),
                                TextEntry::make('evidence_payload.p50_ms')
                                    ->label('p50 Latency')
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Response time percentiles — p50 is median.')
                                    ->suffix(' ms')
                                    ->numeric()
                                    ->placeholder('—'),
                                TextEntry::make('evidence_payload.p95_ms')
                                    ->label('p95 Latency')
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Response time percentiles — p95 is worst-case.')
                                    ->suffix(' ms')
                                    ->numeric()
                                    ->placeholder('—'),
                                TextEntry::make('evidence_payload.p99_ms')
                                    ->label('p99 Latency')
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Response time percentiles — p99 is extreme worst-case.')
                                    ->suffix(' ms')
                                    ->numeric()
                                    ->placeholder('—'),
                                TextEntry::make('evidence_payload.5xx_rate')
                                    ->label('5xx Error Rate')
                                    ->formatStateUsing(fn ($state) => $state !== null ? round($state * 100, 2) . '%' : '—'),
                                TextEntry::make('evidence_payload.inject_fail_rate')
                                    ->label('Injection Fail Rate')
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'How often the consent banner failed to load on pages.')
                                    ->formatStateUsing(fn ($state) => $state !== null ? round($state * 100, 2) . '%' : '—'),
                                TextEntry::make('evidence_payload.cache_hit_rate')
                                    ->label('Cache Hit Rate')
                                    ->formatStateUsing(fn ($state) => $state !== null ? round($state * 100, 1) . '%' : '—'),
                                TextEntry::make('evidence_payload.window_minutes')
                                    ->label('Analysis Window')
                                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'The time period these metrics were measured over.')
                                    ->suffix(' min')
                                    ->placeholder('—'),
                            ])
                            ->collapsible(),
                    ]),

                Action::make('acknowledge')
                    ->label('Acknowledge')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (TrafficAlertState $record) {
                        $record->acknowledge(auth()->id());
                    })
                    ->visible(fn (TrafficAlertState $record) => $record->state === 'open'),

                Action::make('snooze')
                    ->label('Snooze')
                    ->icon('heroicon-o-clock')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (TrafficAlertState $record) {
                        $record->snooze(30, auth()->id());
                    })
                    ->visible(fn (TrafficAlertState $record) => in_array($record->state, ['open', 'suppressed'])),

                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (TrafficAlertState $record) {
                        $record->manualResolve(auth()->id());
                    })
                    ->visible(fn (TrafficAlertState $record) => $record->state !== 'resolved'),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (TrafficAlertState $record) {
                        $record->reopen(auth()->id());
                    })
                    ->visible(fn (TrafficAlertState $record) => $record->state === 'resolved'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
