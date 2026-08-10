<?php

namespace App\Filament\Widgets\Monitoring;

use App\Models\TrafficAlertState;
use Filament\Widgets\Widget;

class OperationsWidget extends Widget
{
    protected static ?int $sort = 10;
    protected string $view = 'filament.widgets.monitoring.operations-widget';
    protected int|string|array $columnSpan = 'full';

    public bool $hasIssues = false;
    public int $runningContainers = 1; 
    public int $unresolvedErrors = 4;

    public function mount()
    {
        // Compute unresolved errors
        $this->unresolvedErrors = \App\Models\CrashReport::whereNull('resolved_at')->count();

        // Compute running containers
        $running = 0;
        try {
            $settings = \App\Models\CoolifySetting::instance();
            if ($settings->isConfigured()) {
                $service = app(\App\Services\CoolifyApiService::class);
                $result = $service->getApplications();
                $apps = $result['apps'] ?? [];
                
                $monitoredUuids = $settings->app_uuids ?? [];
                if (!empty($monitoredUuids)) {
                    $apps = array_filter($apps, fn ($app) => in_array($app['uuid'], $monitoredUuids));
                }

                foreach ($apps as $app) {
                    $parsed = \App\Services\CoolifyApiService::parseStatus($app['status'] ?? 'unknown');
                    if ($parsed['color'] === 'green') {
                        $running++;
                    }
                }
            }
        } catch (\Throwable $e) {}

        $this->runningContainers = $running;
        
        // Auto-expand if there are unresolved issues
        $this->hasIssues = $this->unresolvedErrors > 0 || $this->runningContainers === 0;
    }
}
