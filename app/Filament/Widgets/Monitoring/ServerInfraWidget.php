<?php

namespace App\Filament\Widgets\Monitoring;

use App\Services\ServerInfraService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;

class ServerInfraWidget extends Widget
{
    protected string $view = 'filament.widgets.server-infra';

    protected int|string|array $columnSpan = 'full';

    public bool $isDashboard = false;

    // Refresh every 30 seconds
    protected static ?string $pollingInterval = '30s';

    public array $disk = [];
    public array $memory = [];
    public array $cpu = [];
    public array $uptime = [];
    public array $cleanup = [];
    public array $applications = [];
    public array $cleanupEstimates = [];
    public array $dockerUsage = [];
    public bool $coolifyAvailable = false;
    public bool $sshConfigured = false;
    public string $lastRefresh = '';
    
    // Explicit return tracking for cleanup actions
    public ?string $actionOutput = null;
    public ?string $actionTitle = null;

    // Two-step image prune (warn about stopped services)
    public bool $pendingImagePrune = false;
    public array $stoppedServices = [];
    public string $imagePruneAge = '24h';

    // Auto-cleanup Settings
    public bool $autoCleanupEnabled = false;
    public int $autoCleanupInterval = 60;
    public int $autoCleanupThreshold = 80;

    public function mount(): void
    {
        $settings = \App\Models\CoolifySetting::instance();
        $this->autoCleanupEnabled = $settings->ssh_auto_cleanup_enabled ?? false;
        $this->autoCleanupInterval = $settings->ssh_auto_cleanup_interval ?? 60;
        $this->autoCleanupThreshold = $settings->ssh_auto_cleanup_threshold ?? 80;

        $this->refresh();
    }

    public function updatedAutoCleanupEnabled($value)
    {
        $settings = \App\Models\CoolifySetting::instance();
        $settings->update(['ssh_auto_cleanup_enabled' => $value]);
        Notification::make()->success()->title('Auto-Cleanup Enabled')->send();
    }

    public function updatedAutoCleanupInterval($value)
    {
        $settings = \App\Models\CoolifySetting::instance();
        $settings->update(['ssh_auto_cleanup_interval' => (int) $value]);
        Notification::make()->success()->title('Interval Saved')->send();
    }

    public function updatedAutoCleanupThreshold($value)
    {
        $settings = \App\Models\CoolifySetting::instance();
        $settings->update(['ssh_auto_cleanup_threshold' => (int) $value]);
        Notification::make()->success()->title('Threshold Saved')->send();
    }

    public function refresh(): void
    {
        $service = app(ServerInfraService::class);

        $this->disk    = $service->getDiskUsage();
        $this->memory  = $service->getMemoryUsage();
        $this->cpu     = $service->getCpuLoad();
        $this->uptime  = $service->getUptime();

        $coolifyData = $service->getCoolifyData();
        $this->coolifyAvailable = $coolifyData !== null;

        if ($this->coolifyAvailable) {
            $this->cleanup = $service->getCleanupSettings();
            $apps = $service->getApplications();

            $monitoredUuids = \App\Models\CoolifySetting::instance()->app_uuids ?? [];
            if (!empty($monitoredUuids)) {
                $apps = array_filter($apps, fn ($app) => in_array($app['uuid'], $monitoredUuids));
            }

            $this->applications = $apps;
        }

        $this->cleanupEstimates = $service->getCleanupEstimates();

        // SSH configuration status
        $settings = \App\Models\CoolifySetting::instance();
        $this->sshConfigured = $settings->isSshConfigured();

        // Docker disk usage works via SSH to host — doesn't require Coolify API
        $this->dockerUsage = $service->getDockerDiskUsage();

        $this->lastRefresh = now()->format('H:i:s');
    }

    // ── Safe cleanup actions (no confirmation needed) ───────

    public function clearLaravelCaches(): void
    {
        $result = app(ServerInfraService::class)->clearLaravelCaches();
        $this->notifyResult($result, 'Laravel Caches');
        $this->refresh();
    }

    public function clearRedisCache(): void
    {
        $result = app(ServerInfraService::class)->clearRedisCache();
        $this->notifyResult($result, 'Redis Cache');
        $this->refresh();
    }

    public function clearProxyCache(): void
    {
        $result = app(ServerInfraService::class)->clearProxyCache();
        $this->notifyResult($result, 'Proxy Cache');
        $this->refresh();
    }

    // ── Destructive cleanup actions ─────────────────────────

    public function purgeLogs(): void
    {
        $result = app(ServerInfraService::class)->purgeLogs(7);
        $this->notifyResult($result, 'Log Purge');
        $this->refresh();
    }

    public function pruneBackups(): void
    {
        $result = app(ServerInfraService::class)->pruneBackups();
        $this->notifyResult($result, 'Backup Prune');
        $this->refresh();
    }

    public function purgeHealthHistory(): void
    {
        $result = app(ServerInfraService::class)->purgeHealthHistory(30);
        $this->notifyResult($result, 'Health History');
        $this->refresh();
    }

    public function purgeTrafficMetrics(): void
    {
        $result = app(ServerInfraService::class)->purgeTrafficMetrics(30);
        $this->notifyResult($result, 'Traffic Metrics');
        $this->refresh();
    }

    // ── Server-level cleanup actions ──────────────────────
    // NOTE: These do NOT call $this->refresh() because:
    // 1. refresh() runs another SSH call (getDockerDiskUsage) + Coolify API calls
    // 2. Instead of full refresh(), we call refreshDockerUsage() which only runs docker system df (~5s)
    // 3. Prune (~10s) + docker df (~5s) = ~15s — well within 60s limit

    protected function refreshDockerUsage(): void
    {
        $this->dockerUsage = app(ServerInfraService::class)->getDockerDiskUsage();
    }

    public function pruneDockerImages(): void
    {
        // Step 1: Check for stopped services first
        $service = app(ServerInfraService::class);
        $stopped = $service->getStoppedServices();

        if (!empty($stopped)) {
            // Show warning with list of stopped services
            $this->stoppedServices = $stopped;
            $this->pendingImagePrune = true;

            $names = collect($stopped)->pluck('name')->join(', ');
            Notification::make()
                ->warning()
                ->title('Stopped services detected')
                ->body("These offline apps may lose their images: {$names}. Scroll down to confirm or cancel.")
                ->persistent()
                ->send();
            return;
        }

        // No stopped services — prune immediately
        $this->executePruneImages();
    }

    public function confirmPruneImages(): void
    {
        $this->pendingImagePrune = false;
        $this->stoppedServices = [];
        $this->executePruneImages();
    }

    public function cancelPruneImages(): void
    {
        $this->pendingImagePrune = false;
        $this->stoppedServices = [];
        Notification::make()->info()->title('Image prune cancelled')->send();
    }

    protected function executePruneImages(): void
    {
        $result = app(ServerInfraService::class)->pruneDockerImages($this->imagePruneAge);
        $this->notifyResult($result, 'Docker Images');
        $this->refreshDockerUsage();
    }

    public function pruneDockerContainers(): void
    {
        $result = app(ServerInfraService::class)->pruneDockerContainers();
        $this->notifyResult($result, 'Docker Containers');
        $this->refreshDockerUsage();
    }

    public function pruneDockerVolumes(): void
    {
        $result = app(ServerInfraService::class)->pruneDockerVolumes();
        $this->notifyResult($result, 'Docker Volumes');
        $this->refreshDockerUsage();
    }

    public function pruneDockerBuildCache(): void
    {
        $result = app(ServerInfraService::class)->pruneDockerBuildCache();
        $this->notifyResult($result, 'Build Cache');
        $this->refreshDockerUsage();
    }

    public function pruneDockerAll(): void
    {
        $result = app(ServerInfraService::class)->pruneDockerAll();
        $this->notifyResult($result, 'Docker Full Prune');
        $this->refreshDockerUsage();
    }

    public function vacuumJournalLogs(): void
    {
        $result = app(ServerInfraService::class)->vacuumJournalLogs();
        $this->notifyResult($result, 'Journal Logs');
    }

    public function cleanSystemTemp(): void
    {
        $result = app(ServerInfraService::class)->cleanSystemTemp();
        $this->notifyResult($result, 'System Temp');
    }

    public function triggerDockerCleanup(): void
    {
        $result = app(ServerInfraService::class)->triggerCleanup();
        $this->notifyResult($result, 'Docker Cleanup');
    }

    protected function notifyResult(array $result, string $label): void
    {
        $this->actionTitle = $label;
        $this->actionOutput = $result['message'] ?? 'No output received.';

        if ($result['success']) {
            Notification::make()
                ->success()
                ->title("{$label} completed")
                ->body($result['message'])
                ->send();
        } else {
            Notification::make()
                ->danger()
                ->title("{$label} failed")
                ->body($result['message'])
                ->send();
        }
    }

    public function render(): View
    {
        return view($this->view);
    }
}
