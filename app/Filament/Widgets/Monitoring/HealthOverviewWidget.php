<?php

namespace App\Filament\Widgets\Monitoring;

use App\Models\Domain;
use App\Models\HealthCheckResult;
use App\Services\HealthCheckerService;
use App\Services\OpenRouterService;
use App\Services\TelemetryService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

class HealthOverviewWidget extends Widget implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;
    protected string $view = 'filament.widgets.monitoring.health-overview-widget';

    protected int|string|array $columnSpan = 'full';

    public ?string $selectedDomainId = null;
    public bool $isRunning = false;
    public ?array $latestResult = null;
    public int $consecutiveFailures = 0;
    public ?string $lastSuccessAt = null;
    public ?array $aiDiagnosis = null;
    public bool $isAnalyzing = false;
    public array $runProgress = [];
    public ?string $currentCheck = null;
    public int $progressPercent = 0;
    
    // Scheduler Info
    public bool $schedulerEnabled = false;
    public ?string $schedulerNextRun = null;
    public ?string $schedulerLastRun = null;

    public const CHECK_LABELS = [
        'domain_reachable' => 'Domain reachable through proxy',
        'response_status' => 'Page returns 2xx status',
        'proxy_header' => 'Proxy header present',
        'origin_reachable' => 'Origin server reachable',
        'config_endpoint' => 'Proxy config endpoint',
        'script_injected' => 'YCookies script injected',
        'no_duplicate_injection' => 'No duplicate injection',
        'config_api' => 'Consent config API',
        'consent_endpoint' => 'Consent logging endpoint',
        'headers_correct' => 'Response headers correct',
        'no_csp_block' => 'No CSP blocking',
        'page_load_time' => 'Page load time',
        'ssl_validity' => 'SSL certificate',
        'dns_resolution' => 'DNS resolution',
    ];

    public static function shouldBeDiscoverable(): bool
    {
        return false;
    }

    public array $history = [];

    public function mount(): void
    {
        $first = Domain::where('proxy_enabled', true)->first();
        if ($first) {
            $this->selectedDomainId = (string) $first->id;
            $this->loadDomainData($first);
        }
    }

    public function getDomains(): array
    {
        return Domain::where('proxy_enabled', true)
            ->pluck('name', 'id')
            ->toArray();
    }

    public function updatedSelectedDomainId($value): void
    {
        $domain = Domain::find($value);
        if ($domain) {
            $this->loadDomainData($domain);
        }
    }

    protected function loadDomainData(Domain $domain): void
    {
        $this->lastSuccessAt = $domain->last_health_success_at?->diffForHumans();
        $this->consecutiveFailures = $domain->health_consecutive_failures ?? 0;

        $latest = $domain->healthCheckResults()->first();
        if ($latest) {
            $this->latestResult = [
                'status' => $latest->status,
                'checks' => $latest->checks ?? [],
                'duration_ms' => $latest->duration_ms,
                'checked_at' => $latest->checked_at?->format('M d, Y H:i:s'),
                'source' => $latest->source,
                'checks_total' => $latest->checks_total,
                'checks_passed' => $latest->checks_passed,
                'checks_warned' => $latest->checks_warned,
                'checks_failed' => $latest->checks_failed,
                'checks_expected' => count(array_filter($latest->checks ?? [], fn ($c) => ($c['status'] ?? '') === 'expected')),
            ];
            $this->aiDiagnosis = $latest->ai_diagnosis;
        } else {
            $this->latestResult = null;
            $this->aiDiagnosis = null;
        }

        $this->history = $domain->healthCheckResults()
            ->latest('checked_at')
            ->skip(1) // Skip the one we already show as latest
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'passed' => $r->checks_passed,
                'total' => $r->checks_total,
                'checked_at' => $r->checked_at?->diffForHumans(),
                'duration' => $r->duration_ms,
            ])
            ->toArray();

        // Scheduler logic
        $this->schedulerEnabled = $domain->health_check_enabled ?? false;
        if ($this->schedulerEnabled) {
            $last = $domain->last_health_check_at;
            $this->schedulerLastRun = $last ? $last->diffForHumans() : 'Never';
            
            if ($last) {
                // Determine 'Last' label format
                $lastFormatted = '';
                if ($last->isToday()) {
                    $lastFormatted = 'Today at ' . $last->format('H:i');
                } elseif ($last->isYesterday()) {
                    $lastFormatted = 'Yesterday at ' . $last->format('H:i');
                } else {
                    $lastFormatted = $last->format('M d') . ' at ' . $last->format('H:i');
                }
                $this->schedulerLastRun = $lastFormatted;

                $interval = $domain->health_check_interval_minutes ?? 60;
                $next = $last->copy()->addMinutes($interval);
                
                // Determine 'Next' label format
                $nextFormatted = '';
                if ($next->isToday()) {
                    $nextFormatted = 'Today at ' . $next->format('H:i');
                } elseif ($next->isTomorrow()) {
                    $nextFormatted = 'Tomorrow at ' . $next->format('H:i');
                } else {
                    $nextFormatted = $next->format('M d') . ' at ' . $next->format('H:i');
                }

                if ($next->isPast()) {
                    $this->schedulerNextRun = $nextFormatted . ' (Pending)';
                } else {
                    $this->schedulerNextRun = $nextFormatted;
                }
            } else {
                $this->schedulerNextRun = 'Pending execution';
            }
        } else {
            $this->schedulerLastRun = null;
            $this->schedulerNextRun = null;
        }

        $this->isAnalyzing = false;
    }

    public function runNow(): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            Notification::make()->warning()->title('Select a domain')->send();
            return;
        }

        $checker = app(HealthCheckerService::class);
        if (! $checker->canRunNow($domain)) {
            Notification::make()->warning()->title('Too soon')->body('Please wait 5 minutes.')->send();
            return;
        }

        $this->isRunning = true;
        $cacheKey = 'health-check-progress:'.$domain->id;
        Cache::put($cacheKey, ['completed' => [], 'current' => null, 'total' => count(self::CHECK_LABELS), 'done' => false], 300);

        try {
            set_time_limit(120);
            $result = $checker->run($domain, function ($name, $status) use ($cacheKey) {
                $p = Cache::get($cacheKey);
                $p['completed'][] = ['name' => $name, 'status' => $status];
                $p['current'] = null;
                Cache::put($cacheKey, $p, 300);
            }, function ($name) use ($cacheKey) {
                $p = Cache::get($cacheKey);
                $p['current'] = $name;
                Cache::put($cacheKey, $p, 300);
            });

            $record = HealthCheckResult::create([
                'domain_id' => $domain->id,
                'domain_name' => $domain->name,
                'source' => 'manual',
                'status' => $result['status'],
                'checks_total' => $result['checks_total'],
                'checks_passed' => $result['checks_passed'],
                'checks_warned' => $result['checks_warned'],
                'checks_failed' => $result['checks_failed'],
                'checks' => $result['checks'],
                'response_times' => $result['response_times'],
                'headers' => $result['headers'],
                'evidence' => $result['evidence'],
                'duration_ms' => $result['duration_ms'],
                'checked_at' => now(),
            ]);

            $domain->update([
                'health_status' => $result['status'],
                'last_health_check_at' => now(),
                'last_health_success_at' => $result['status'] === 'healthy' ? now() : $domain->last_health_success_at,
                'health_consecutive_failures' => $result['status'] === 'healthy' ? 0 : $domain->health_consecutive_failures + 1,
            ]);

            $this->loadDomainData($domain->fresh());

            // AI Analysis
            if (\App\Models\AiSetting::instance()->isConfigured()) {
                $diagnosis = app(OpenRouterService::class)->analyzeHealthCheck($domain->name, $result);
                if ($diagnosis) {
                    $this->aiDiagnosis = $diagnosis;
                    $record->update(['ai_diagnosis' => $diagnosis]);
                }
            }

            Notification::make()->success()->title('Health check complete')->send();
            TelemetryService::send($record);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Health check failed')->body($e->getMessage())->send();
        } finally {
            $this->isRunning = false;
            Cache::forget($cacheKey);
            $this->dispatch('health-check-done');
        }
    }

    public function pollProgress(): void
    {
        if (! $this->isRunning || ! $this->selectedDomainId) return;

        $progress = Cache::get('health-check-progress:'.$this->selectedDomainId);
        if (!$progress) return;

        $this->runProgress = $progress['completed'] ?? [];
        $this->currentCheck = $progress['current'] ?? null;
        $total = $progress['total'] ?? count(self::CHECK_LABELS);
        $completed = count($this->runProgress);
        $this->progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
    }

    public function analyzeWithAI(): void
    {
        if (! $this->latestResult || ! $this->selectedDomainId) return;

        $this->isAnalyzing = true;
        try {
            $domain = Domain::find($this->selectedDomainId);
            $latest = $domain->healthCheckResults()->first();
            $diagnosis = app(OpenRouterService::class)->analyzeHealthCheck($domain->name, $latest->toArray());
            if ($diagnosis) {
                $this->aiDiagnosis = $diagnosis;
                $latest->update(['ai_diagnosis' => $diagnosis]);
                Notification::make()->success()->title('AI analysis complete')->send();
            } else {
                Notification::make()->warning()->title('Analysis failed')->body('No response from AI service. Check your API key or logs.')->send();
            }
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('AI analysis error')->body($e->getMessage())->send();
        } finally {
            $this->isAnalyzing = false;
        }
    }

    public function manageSettingsAction(): Action
    {
        return Action::make('manageSettings')
            ->label('Scheduler Settings')
            ->icon('heroicon-m-cog-8-tooth')
            ->color('gray')
            ->modalHeading(fn () => 'Health Check Settings - ' . ($this->getDomains()[$this->selectedDomainId] ?? 'Domain'))
            ->modalDescription('Configure automatic health checks and metrics for this domain.')
            ->fillForm(function (): array {
                if (!$this->selectedDomainId) return [];
                $domain = Domain::find($this->selectedDomainId);
                
                $allChecks = array_keys(self::CHECK_LABELS);
                $state = $domain->health_check_overrides ?? [];
                $enabled = [];
                foreach ($allChecks as $check) {
                    if (($state[$check] ?? '') !== 'disabled') {
                        $enabled[] = $check;
                    }
                }

                return [
                    'health_check_enabled' => $domain->health_check_enabled,
                    'health_check_mode' => $domain->health_check_mode ?? 'scheduler',
                    'health_check_interval_minutes' => $domain->health_check_interval_minutes ?? 60,
                    'health_check_max_per_day' => $domain->health_check_max_per_day ?? 24,
                    'health_check_overrides' => $enabled,
                ];
            })
            ->form([
                Toggle::make('health_check_enabled')
                    ->label('Auto-Scan Enabled')
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Runs health checks on your domains automatically on a schedule.')
                    ->helperText('Automatically check this domain\'s health on a schedule.')
                    ->live(),

                Select::make('health_check_mode')
                    ->label('Scan Mode')
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'How scans are triggered — via Laravel\'s scheduler or external cron.')
                    ->options([
                        'scheduler' => 'Laravel Scheduler',
                    ])
                    ->default('scheduler')
                    ->helperText('💡 Runs according to the backend Laravel scheduler (e.g., via cron).')
                    ->disabled()
                    ->hidden(fn (Get $get) => !$get('health_check_enabled')),

                Grid::make(2)
                    ->schema([
                        TextInput::make('health_check_interval_minutes')
                            ->label('Min Interval (Minutes)')
                            ->helperText('Wait at least this many minutes between automated checks.')
                            ->numeric()
                            ->minValue(15)
                            ->default(60),
                        TextInput::make('health_check_max_per_day')
                            ->label('Max Scans / Day')
                            ->helperText('Safety limit to control check frequency.')
                            ->numeric()
                            ->minValue(1)
                            ->default(24),
                    ])
                    ->hidden(fn (Get $get) => !$get('health_check_enabled')),

                CheckboxList::make('health_check_overrides')
                    ->label('Enabled Metrics')
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Which individual health checks to run during each scan.')
                    ->helperText('Uncheck a metric to ignore it when grading this domain\'s health.')
                    ->options(self::CHECK_LABELS)
                    ->descriptions([
                        'proxy_header' => 'Confirms the proxy is actively handling this domain\'s traffic.',
                        'no_csp_block' => 'Checks that browser security policies aren\'t blocking the consent script.',
                        'no_duplicate_injection' => 'Ensures the consent script isn\'t being loaded more than once.',
                        'config_endpoint' => 'Verifies the proxy can serve the consent configuration file.',
                    ])
                    ->columns(2)
                    ->gridDirection('vertical')
                    ->hidden(fn (Get $get) => !$get('health_check_enabled')),
            ])
            ->action(function (array $data) {
                $domain = Domain::find($this->selectedDomainId);
                if (!$domain) return;

                $allChecks = array_keys(self::CHECK_LABELS);
                $overrides = [];
                $enabled = $data['health_check_overrides'] ?? [];
                
                foreach ($allChecks as $check) {
                    if (!in_array($check, $enabled)) {
                        $overrides[$check] = 'disabled';
                    }
                }

                $domain->update([
                    'health_check_enabled' => $data['health_check_enabled'],
                    'health_check_mode' => $data['health_check_mode'] ?? 'scheduler',
                    'health_check_interval_minutes' => $data['health_check_interval_minutes'],
                    'health_check_max_per_day' => $data['health_check_max_per_day'],
                    'health_check_overrides' => $overrides,
                ]);

                Notification::make()->success()->title('Settings saved')->send();
                $this->loadDomainData($domain);
            });
    }
}
