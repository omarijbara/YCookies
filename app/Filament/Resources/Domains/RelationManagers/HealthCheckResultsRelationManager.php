<?php

namespace App\Filament\Resources\Domains\RelationManagers;

use App\Models\HealthCheckResult;
use App\Services\HealthCheckerService;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HealthCheckResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'healthCheckResults';

    protected static ?string $title = 'Health Reports & Settings';

    // Specify the owner record type for IDE help
    public function getOwnerRecord(): \App\Models\Domain
    {
        return parent::getOwnerRecord();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Health Check Settings')
                    ->description('Configure how and when health checks run for this domain.')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Toggle::make('health_check_enabled')
                                    ->label('Enable Health Checks')
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('health_check_interval_minutes')
                                    ->label('Interval (min)')
                                    ->numeric()
                                    ->minValue(15)
                                    ->default(60)
                                    ->columnSpan(1),
                                Forms\Components\Placeholder::make('last_health_check_at')
                                    ->label('Last Check')
                                    ->content(fn ($record) => $record?->last_health_check_at?->diffForHumans() ?? 'Never')
                                    ->columnSpan(1),
                            ]),
                        
                        Forms\Components\CheckboxList::make('health_check_overrides')
                            ->label('Enabled Checks')
                            ->description('Uncheck a metric to disable it for this domain.')
                            ->options([
                                'domain_reachable'       => 'Domain Reachability',
                                'proxy_header'           => 'Proxy Header Verification',
                                'script_injected'        => 'Consent Script Injection',
                                'config_endpoint'        => 'Configuration Endpoint',
                                'config_api'             => 'Consent API Availability',
                                'response_status'        => 'HTTP Response Status',
                                'origin_reachable'       => 'Origin Server Direct Access',
                                'consent_endpoint'       => 'Consent Logging Visibility',
                                'no_duplicate_injection' => 'Duplicate Injection Prevention',
                                'headers_correct'        => 'Correct Response Headers',
                                'no_csp_block'           => 'No CSP Blocking Issues',
                                'page_load_time'         => 'Performance / Load Time',
                                'ssl_validity'           => 'SSL Certificate Validity',
                                'dns_resolution'         => 'DNS Resolution Health',
                            ])
                            ->columns(2)
                            ->gridDirection('vertical')
                            // Store as an array of disabled check names (overrides)
                            ->dehydrateStateUsing(function ($state) {
                                $allChecks = [
                                    'domain_reachable', 'proxy_header', 'script_injected', 'config_endpoint', 'config_api',
                                    'response_status', 'origin_reachable', 'consent_endpoint', 'no_duplicate_injection',
                                    'headers_correct', 'no_csp_block', 'page_load_time', 'ssl_validity', 'dns_resolution'
                                ];
                                
                                $overrides = [];
                                foreach ($allChecks as $check) {
                                    if (!in_array($check, $state ?? [])) {
                                        $overrides[$check] = 'disabled';
                                    }
                                }
                                return $overrides;
                            })
                            // Hydrate: only include checks NOT marked as 'disabled'
                            ->formatStateUsing(function ($state) {
                                if (!is_array($state)) return [
                                    'domain_reachable', 'proxy_header', 'script_injected', 'config_endpoint', 'config_api',
                                    'response_status', 'origin_reachable', 'consent_endpoint', 'no_duplicate_injection',
                                    'headers_correct', 'no_csp_block', 'page_load_time', 'ssl_validity', 'dns_resolution'
                                ];

                                $enabled = [];
                                $allChecks = [
                                    'domain_reachable', 'proxy_header', 'script_injected', 'config_endpoint', 'config_api',
                                    'response_status', 'origin_reachable', 'consent_endpoint', 'no_duplicate_injection',
                                    'headers_correct', 'no_csp_block', 'page_load_time', 'ssl_validity', 'dns_resolution'
                                ];

                                foreach ($allChecks as $check) {
                                    if (($state[$check] ?? '') !== 'disabled') {
                                        $enabled[] = $check;
                                    }
                                }
                                return $enabled;
                            }),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => $record?->healthCheckResults()?->count() > 0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('checked_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Timestamp'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'failing' => 'danger',
                        default => 'gray',
                    })
                    ->label('Overall'),

                Tables\Columns\TextColumn::make('checks_passed')
                    ->label('Checks')
                    ->formatStateUsing(fn ($record) => "{$record->checks_passed}/{$record->checks_total}"),

                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->suffix(' ms'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                \Filament\Actions\Action::make('run_now')
                    ->label('Run Health Check')
                    ->icon('heroicon-m-play')
                    ->action(function () {
                        $domain = $this->getOwnerRecord();
                        
                        // Notify start
                        \Filament\Notifications\Notification::make()
                            ->title('Health Check Started')
                            ->body("Starting automated verification for {$domain->name}...")
                            ->info()
                            ->send();

                        // Run the service
                        $service = app(HealthCheckerService::class);
                        $results = $service->run($domain);

                        // Save results
                        $domain->healthCheckResults()->create([
                            'status' => $results['status'],
                            'checks_total' => $results['checks_total'],
                            'checks_passed' => $results['checks_passed'],
                            'checks_warned' => $results['checks_warned'],
                            'checks_failed' => $results['checks_failed'],
                            'checks_expected' => $results['checks_expected'],
                            'duration_ms' => $results['duration_ms'],
                            'check_summary' => $results['checks'],
                            'evidence' => $results['evidence'],
                            'checked_at' => now(),
                        ]);

                        // Update domain status
                        $domain->update([
                            'health_status' => $results['status'],
                            'last_health_check_at' => now(),
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Health Check Complete')
                            ->body("Status: " . ucfirst($results['status']))
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->modalHeading('Health Check Analysis')
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn ($record) => view('filament.monitoring.health-check-results-modal', [
                        'latestResult' => $record->toArray(),
                        'aiDiagnosis' => $record->ai_diagnosis,
                    ])),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('checked_at', 'desc');
    }
}
