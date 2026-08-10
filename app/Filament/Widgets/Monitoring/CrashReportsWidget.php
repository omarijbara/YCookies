<?php

namespace App\Filament\Widgets\Monitoring;

use App\Models\CrashReport;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CrashReportsWidget extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    public bool $isDashboard = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CrashReport::query())
            ->poll('15s')
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color(function ($state) {
                        if (str_starts_with($state, 'glitchtip')) {
                            return 'danger';
                        }
                        
                        return match ($state) {
                            'health-checker'   => 'danger',
                            'cookie-scanner'   => 'warning',
                            'manifest-compiler', 'manifest-publisher' => 'info',
                            'coolify-sync'     => 'primary',
                            'webhook-delivery' => 'warning',
                            default            => 'gray',
                        };
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('level')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'critical' => 'danger',
                        'error'    => 'danger',
                        'warning'  => 'warning',
                        default    => 'gray',
                    }),
                Tables\Columns\TextColumn::make('message')
                    ->limit(60)
                    ->searchable()
                    ->tooltip(fn ($state) => $state),
                Tables\Columns\TextColumn::make('occurrence_count')
                    ->label('Hits')
                    ->extraHeaderAttributes(['title' => 'The total number of times this exact system crash has occurred.'])
                    ->numeric()
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('telemetry_sent_at')
                    ->label('Pushed')
                    ->extraHeaderAttributes(['title' => 'Indicates if this log has been successfully transmitted to the Improve Ypsilon Hub for the Daily AI Intelligence Report.'])
                    ->tooltip('Successfully pushed to Improve Ypsilon Telemetry Hub')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->getStateUsing(fn ($record) => $record->telemetry_sent_at !== null),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\IconColumn::make('resolved_at')
                    ->label('Resolved')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->options([
                        'laravel-ycookies'    => 'Global Exception',
                        'cookie-scanner'      => 'Cookie Scanner',
                        'health-checker'      => 'Health Checker',
                        'digest-notification' => 'Digest Notification',
                        'manifest-compiler'   => 'Manifest Compiler',
                        'manifest-publisher'  => 'Manifest Publisher',
                        'coolify-sync'        => 'Coolify Sync',
                        'webhook-delivery'    => 'Webhook Delivery',
                        'telemetry-push'      => 'Telemetry Push',
                        'node-proxy'          => 'Node Proxy',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make()
                    ->form([
                        \Filament\Schemas\Components\Grid::make(3)->schema([
                            \Filament\Forms\Components\TextInput::make('source')->disabled(),
                            \Filament\Forms\Components\TextInput::make('level')->disabled(),
                            \Filament\Forms\Components\TextInput::make('last_seen_at')->disabled(),
                        ]),
                        \Filament\Forms\Components\Textarea::make('message')
                            ->disabled()
                            ->columnSpanFull()
                            ->rows(3),
                        \Filament\Forms\Components\Textarea::make('stack_trace')
                            ->disabled()
                            ->columnSpanFull()
                            ->rows(15)
                            ->extraAttributes(['class' => 'font-mono text-xs']),
                        \Filament\Forms\Components\KeyValue::make('context')
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
                \Filament\Actions\Action::make('resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->hidden(fn ($record) => $record->resolved_at)
                    ->action(fn ($record) => $record->update(['resolved_at' => now()])),
                \Filament\Actions\Action::make('retry_push')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->label('Retry Push')
                    ->hidden(fn ($record) => $record->telemetry_sent_at !== null)
                    ->action(function ($record) {
                        $error = [
                            'level'            => $record->level,
                            'source'           => $record->source,
                            'message'          => $record->message,
                            'stack_trace'      => $record->stack_trace,
                            'context'          => $record->context,
                            'occurred_at'      => $record->last_seen_at->toIso8601String(),
                            'fingerprint'      => $record->fingerprint,
                            'occurrence_count' => $record->occurrence_count,
                        ];
                        \App\Jobs\ForwardErrorsToImprove::dispatch([$error], [$record->id]);
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);

        if ($this->isDashboard) {
            $table->searchable(false)
                ->filters([])
                ->actions([])
                ->bulkActions([]);
        }

        return $table;
    }
}
