<?php

namespace App\Filament\Pages;

use App\Models\DiscoveredResource;
use App\Models\Domain;
use App\Models\DomainPageSet;
use App\Models\ScanResult;
use App\Models\ScriptBlocker;
use App\Models\SmtpSetting;
use App\Models\User;
use App\Services\OutboundWebhookDispatcher;
use App\Services\ScriptScannerService;
use App\Services\TemplateLibraryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

class ScriptScanner extends Page
{
    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.script-scanner';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-magnifying-glass-circle';
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.scanner');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.tools');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.script_scanner');
    }

    // Livewire state
    public ?string $selectedDomainId = null;

    public string $customDomainUrl = '';

    public bool $isScanning = false;

    public bool $isBareMode = false;

    public bool $hasResults = false;

    public array $protectedScripts = [];

    public array $suggestedScripts = [];

    public array $unknownScripts = [];

    public array $unblockedScripts = [];

    public array $rawScripts = [];

    public ?string $scanError = null;

    public ?string $scannedDomainName = null;

    public array $scanStages = [];

    public array $discoveredPages = [];

    // Live progress tracking
    public int $scanProgress = 0;        // 0-100 percentage

    public string $scanCurrentPage = '';  // URL currently being scanned

    public array $pagesToScan = [];       // Queue of pages to scan

    public int $scanPageIndex = 0;        // Current index in pagesToScan

    public array $scanLog = [];           // Log of scanned pages with status

    public array $pageScriptsMap = [];    // Page URL => [scripts found]

    public array $allCollectedUrls = [];  // Accumulated script URLs during scan

    public array $allCollectedUnblockedUrls = [];  // Accumulated unblocked URLs during scan

    // Manual pages input (bulk URLs, one per line)
    public string $manualPagesInput = '';

    // Report email settings
    public string $reportEmail = '';

    public bool $reportEnabled = false;

    // Scheduler form data (bound via form statePath)
    public array $data = [];

    public ?string $lastScanAt = null;

    // Scan history
    public array $scanHistory = [];   // List of past scans for selected domain

    public ?int $viewingScanId = null; // Currently viewed scan ID

    public string $viewingScanSource = ''; // Source type of viewed scan (auto/manual/scheduled)

    /** @var list<int|string> */
    public array $selectedHistoryScanIds = [];

    // Page sets & discovery
    public array $pageSetsData = [];       // Page sets for display

    public array $priorityPagesData = [];  // User-selected priority pages

    public array $autoPriorityData = [];   // Auto-detected priority pages

    public int $discoveredCount = 0;       // Total discovered pages

    public int $currentCycle = 1;

    public int $currentSetIndex = 0;

    public ?string $lastDiscoveryAt = null;

    public bool $isDiscovering = false;

    public array $discoveryProgress = []; // Live progress from background job

    public string $priorityPagesInput = ''; // Textarea for priority pages

    // Set viewer & editor
    public ?int $viewingSetIndex = null;    // Which set is expanded (null = none)

    public array $viewingSetPages = [];     // Pages in the currently viewed set

    public string $editingSetPages = '';    // Textarea for editing set pages

    public bool $isEditingSet = false;      // Whether editing mode is active

    // Re-discover confirmation
    public bool $showRediscoverConfirm = false;

    // Visitor Discovery
    public array $discoveredResources = [];
    public int $discoveredPendingCount = 0;

    public function mount(): void
    {
        // Auto-select first domain
        $first = Domain::first();
        if ($first) {
            $this->selectedDomainId = $first->id;
            $this->loadSchedulerSettings($first);
        }
    }

    /**
     * When selectedDomainId changes, load the scheduler settings for that domain.
     */
    public function updatedSelectedDomainId($value): void
    {
        if ($value === 'custom') {
            $this->resetResults();
            $this->selectedHistoryScanIds = [];
            $this->scanHistory = ScanResult::whereNull('domain_id')
                ->orderBy('scanned_at', 'desc')
                ->limit(30)
                ->get(['id', 'domain_id', 'domain_name', 'scanned_at', 'source', 'total_scripts', 'protected_count', 'suggested_count', 'unknown_count', 'unblocked_count', 'pages_scanned_count'])
                ->toArray();

            if (! empty($this->scanHistory)) {
                $this->viewScan($this->scanHistory[0]['id']);
            }

            return;
        }

        $domain = Domain::find($value);
        if ($domain) {
            $this->loadSchedulerSettings($domain);
        }
    }

    protected function loadDiscoveredResources(?int $domainId): void
    {
        if (! $domainId || $domainId === 'custom') {
            $this->discoveredResources = [];
            $this->discoveredPendingCount = 0;
            return;
        }

        $this->discoveredResources = DiscoveredResource::withoutGlobalScopes()
            ->where('domain_id', $domainId)
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->toArray();

        $this->discoveredPendingCount = collect($this->discoveredResources)
            ->where('status', 'pending')
            ->count();
    }

    public function ignoreDiscoveredResource(int $id): void
    {
        DiscoveredResource::withoutGlobalScopes()
            ->where('id', $id)
            ->update(['status' => 'ignored', 'resolved_at' => now()]);

        $this->loadDiscoveredResources($this->selectedDomainId);

        Notification::make()->success()->title('Resource ignored')->send();
    }

    public function resolveDiscoveredResource(int $id): void
    {
        $resource = DiscoveredResource::withoutGlobalScopes()->find($id);
        if (! $resource) return;

        $domain = Domain::withoutGlobalScopes()->find($resource->domain_id);
        if (! $domain) return;

        // Create a custom ScriptBlocker from the discovered resource
        $type = $resource->resource_type === 'style'
            ? ScriptBlocker::TYPE_STYLE
            : ScriptBlocker::TYPE_SCRIPT;

        $blocker = ScriptBlocker::create([
            'domain_id' => $domain->id,
            'group_id' => $domain->group_id,
            'key' => 'custom-' . \Illuminate\Support\Str::slug($resource->provider_host),
            'name' => ['en' => $resource->provider_host, 'de' => $resource->provider_host],
            'phrases' => [$resource->provider_host],
            'is_active' => true,
            'blocker_type' => $type,
        ]);

        $resource->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_to_type' => 'script_blocker',
            'resolved_to_id' => $blocker->id,
        ]);

        $this->loadDiscoveredResources($this->selectedDomainId);

        Notification::make()->success()->title("Created blocker for {$resource->provider_host}")->send();
    }

    /**
     * Load scheduler settings from a domain into Livewire properties.
     */
    protected function loadSchedulerSettings(Domain $domain): void
    {
        $this->lastScanAt = $domain->last_scan_at
            ? $domain->last_scan_at->diffForHumans()
            : null;

        // Load manual pages
        $pages = $domain->scan_pages ?? [];
        $this->manualPagesInput = implode("\n", $pages);

        // Load report settings
        $this->reportEmail = $domain->report_email ?? '';
        $this->reportEnabled = (bool) $domain->report_enabled;

        // Load scan history
        $this->loadScanHistory($domain);

        // Load page set data
        $this->loadPageSetData($domain);

        // Load visitor discovery data
        $this->loadDiscoveredResources($domain->id);

        $this->data = array_merge($this->data, [
            'schedulerEnabled' => (bool) $domain->scheduler_enabled,
            'schedulerMode' => $domain->scheduler_mode ?? 'traffic',
            'lockMinutes' => max(60, (int) ($domain->lock_minutes ?? 60)),
            'maxScansPerDay' => (int) ($domain->max_scans_per_day ?? 10),
            'webcronToken' => $domain->webcron_token ?? '',
        ]);
    }

    /**
     * Load page set discovery data for display.
     */
    protected function loadPageSetData(Domain $domain): void
    {
        $this->discoveredCount = $domain->discovered_pages_count ?? 0;
        $this->currentCycle = $domain->current_cycle ?? 1;
        $this->currentSetIndex = $domain->current_set_index ?? 0;
        $this->lastDiscoveryAt = $domain->last_discovery_at
            ? $domain->last_discovery_at->diffForHumans()
            : null;

        $this->autoPriorityData = $domain->auto_priority_pages ?? [];
        $this->priorityPagesData = $domain->priority_pages ?? [];
        $this->priorityPagesInput = implode("\n", $domain->priority_pages ?? []);

        // Load page sets with status (include id for viewer/editor)
        $this->pageSetsData = $domain->pageSets()
            ->where('cycle_number', $domain->current_cycle ?? 1)
            ->get()
            ->map(fn (DomainPageSet $set) => [
                'id' => $set->id,
                'index' => $set->set_index,
                'page_count' => $set->page_count,
                'scanned' => $set->last_scanned_at !== null,
                'scanned_at' => $set->last_scanned_at?->diffForHumans(),
            ])
            ->toArray();

        // Close set viewer if it was open
        $this->viewingSetIndex = null;
        $this->viewingSetPages = [];
        $this->editingSetPages = '';
        $this->isEditingSet = false;
    }

    /**
     * Open a set to view its pages.
     */
    public function viewSet(int $index): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $set = $domain->pageSets()
            ->where('set_index', $index)
            ->where('cycle_number', $domain->current_cycle ?? 1)
            ->first();

        if (! $set) {
            return;
        }

        $this->viewingSetIndex = $index;
        $this->viewingSetPages = $set->pages ?? [];
        $this->editingSetPages = implode("\n", $set->pages ?? []);
        $this->isEditingSet = false;
    }

    /**
     * Close the set viewer.
     */
    public function closeSet(): void
    {
        $this->viewingSetIndex = null;
        $this->viewingSetPages = [];
        $this->editingSetPages = '';
        $this->isEditingSet = false;
    }

    /**
     * Toggle editing mode for the current set.
     */
    public function toggleEditSet(): void
    {
        $this->isEditingSet = ! $this->isEditingSet;
    }

    /**
     * Save edited pages back to the DomainPageSet.
     */
    public function saveSetPages(): void
    {
        if ($this->viewingSetIndex === null) {
            return;
        }

        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $set = $domain->pageSets()
            ->where('set_index', $this->viewingSetIndex)
            ->where('cycle_number', $domain->current_cycle ?? 1)
            ->first();

        if (! $set) {
            return;
        }

        // Parse the textarea into an array of URLs
        $newPages = array_values(array_filter(
            array_map('trim', explode("\n", $this->editingSetPages)),
            fn ($url) => ! empty($url)
        ));

        $set->update([
            'pages' => $newPages,
            'page_count' => count($newPages),
        ]);

        // Update local state
        $this->viewingSetPages = $newPages;
        $this->isEditingSet = false;

        // Refresh the pageSetsData to show updated count
        $this->loadPageSetData($domain);
        // Re-open the same set after refresh (viewingSetIndex is already preserved)
        $this->viewingSetPages = $newPages;

        // Update total discovered count
        $totalPages = $domain->pageSets()
            ->where('cycle_number', $domain->current_cycle ?? 1)
            ->sum('page_count');
        $domain->update(['discovered_pages_count' => $totalPages]);
        $this->discoveredCount = $totalPages;

        Notification::make()
            ->success()
            ->title('Set '.($this->viewingSetIndex + 1).' updated')
            ->body(count($newPages).' pages saved.')
            ->send();
    }

    /**
     * Show re-discover confirmation dialog.
     */
    public function confirmRediscover(): void
    {
        $this->showRediscoverConfirm = true;
    }

    /**
     * Cancel re-discover.
     */
    public function cancelRediscover(): void
    {
        $this->showRediscoverConfirm = false;
    }

    /**
     * Spawn background discovery process (avoids Livewire 30s timeout).
     */
    public function discoverAllPages(): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $this->isDiscovering = true;
        $this->discoveryProgress = [];
        $this->showRediscoverConfirm = false;

        // Mark as "discovering" + clear old progress file
        $domain->update(['last_discovery_at' => null]);
        $progressFile = storage_path('app/discovery_'.$domain->id.'.json');
        if (file_exists($progressFile)) {
            @unlink($progressFile);
        }

        // Spawn artisan command as a truly separate background process
        // PHP_BINARY may be php-cgi.exe in web server context (e.g. Herd) — use CLI php.exe instead
        $php = PHP_BINARY ?: 'php';
        if (str_contains(strtolower($php), 'php-cgi')) {
            $phpDir = dirname($php);
            $cliPhp = $phpDir.DIRECTORY_SEPARATOR.'php.exe';
            $php = file_exists($cliPhp) ? $cliPhp : 'php';
        }
        $artisan = base_path('artisan');
        $cmd = "\"{$php}\" \"{$artisan}\" pages:discover {$domain->id}";

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: start /B needs an empty title ("") when the command path is quoted
            $fullCmd = "start /B \"\" {$cmd} > NUL 2>&1";
            Log::info("Discovery spawn: {$fullCmd}");
            pclose(popen($fullCmd, 'r'));
        } else {
            pclose(popen("{$cmd} > /dev/null 2>&1 &", 'r'));
        }

        Notification::make()
            ->info()
            ->title('Discovery started')
            ->body("Scanning {$domain->name} for pages in the background…")
            ->send();
    }

    /**
     * Called by wire:poll when isDiscovering is true.
     * Checks if the background job has finished.
     */
    public function pollDiscovery(): void
    {
        if (! $this->isDiscovering) {
            return;
        }

        try {
            $domain = Domain::find($this->selectedDomainId);
            if (! $domain) {
                return;
            }

            // Read progress from JSON file written by background process
            $progress = ScriptScannerService::readProgress($domain->id);
            if ($progress) {
                $this->discoveryProgress = $progress;
            }

            // Check if discovery is complete (job sets last_discovery_at)
            $domain->refresh();
            if ($domain->last_discovery_at !== null) {
                $this->isDiscovering = false;
                $this->loadPageSetData($domain);

                Notification::make()
                    ->success()
                    ->title('Discovery complete')
                    ->body("{$domain->discovered_pages_count} pages organized into ".count($this->pageSetsData).' sets.')
                    ->send();
            }
        } catch (\Throwable $e) {
            Log::error('pollDiscovery error: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
            $this->isDiscovering = false;
            Notification::make()
                ->danger()
                ->title('Discovery error')
                ->body('An error occurred during discovery.')
                ->send();
        }
    }

    /**
     * Save user-selected priority pages.
     */
    public function savePriorityPages(): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $pages = array_filter(
            array_map('trim', explode("\n", $this->priorityPagesInput))
        );
        $pages = array_slice(array_values($pages), 0, 30);

        $domain->update(['priority_pages' => $pages]);
        $this->priorityPagesData = $pages;

        Notification::make()
            ->success()
            ->title('Priority pages saved')
            ->body(count($pages).' priority pages will be scanned every time.')
            ->send();
    }

    /**
     * Load scan history for the selected domain and restore latest scan results.
     */
    protected function loadScanHistory(Domain $domain): void
    {
        // Load all scans for this domain (newest first), limited to 50
        $this->scanHistory = $domain->scanResults()
            ->orderBy('scanned_at', 'desc')
            ->limit(50)
            ->get(['id', 'domain_id', 'domain_name', 'scanned_at', 'source', 'total_scripts', 'protected_count', 'suggested_count', 'unknown_count', 'unblocked_count', 'pages_scanned_count'])
            ->toArray();

        $this->selectedHistoryScanIds = [];

        // Load latest scan results if available
        if (! empty($this->scanHistory)) {
            $this->viewScan($this->scanHistory[0]['id']);
        } else {
            $this->resetResults();
        }
    }

    /**
     * View a specific past scan's full results.
     */
    public function viewScan(int $scanId): void
    {
        $scan = ScanResult::find($scanId);
        $expectedDomainId = $this->selectedDomainId === 'custom' ? null : (int) $this->selectedDomainId;
        if (! $scan || $scan->domain_id !== $expectedDomainId) {
            return;
        }

        $this->viewingScanId = $scanId;
        $this->protectedScripts = $scan->protected_scripts ?? [];
        $this->suggestedScripts = $scan->suggested_scripts ?? [];
        $this->unknownScripts = $scan->unknown_scripts ?? [];
        $this->unblockedScripts = $scan->unblocked_scripts ?? [];
        $this->rawScripts = $scan->raw_scripts ?? [];
        $this->scanStages = $scan->scan_stages ?? [];
        $this->discoveredPages = $scan->pages_scanned ?? [];
        $this->scanLog = $scan->scan_log ?? [];
        $this->scannedDomainName = $scan->domain_name;
        $this->viewingScanSource = $scan->source ?? 'auto';
        $this->lastScanAt = $scan->scanned_at ? $scan->scanned_at->diffForHumans() : null;
        $this->hasResults = $scan->total_scripts > 0 || ! empty($this->protectedScripts)
            || ! empty($this->suggestedScripts) || ! empty($this->unknownScripts);
    }

    /**
     * Reset all results to empty state.
     */
    protected function resetResults(): void
    {
        $this->viewingScanId = null;
        $this->viewingScanSource = '';
        $this->hasResults = false;
        $this->protectedScripts = [];
        $this->suggestedScripts = [];
        $this->unknownScripts = [];
        $this->unblockedScripts = [];
        $this->rawScripts = [];
        $this->scanStages = [];
        $this->discoveredPages = [];
        $this->scanLog = [];
        $this->scannedDomainName = null;
    }

    public function deleteScanAction(): Action
    {
        return Action::make('deleteScan')
            ->label(__('ycookies.script_scanner.delete_scan'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->iconButton()
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('ycookies.script_scanner.delete_scan'))
            ->modalDescription(__('ycookies.script_scanner.confirm_delete_selected'))
            ->modalSubmitActionLabel(__('ycookies.script_scanner.delete_scan'))
            ->action(fn (array $arguments) => $this->deleteScan($arguments['scanId']));
    }

    public function deleteSelectedHistoryScansAction(): Action
    {
        return Action::make('deleteSelectedHistoryScans')
            ->label(__('ycookies.script_scanner.delete_selected'))
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('ycookies.script_scanner.delete_selected'))
            ->modalDescription(__('ycookies.script_scanner.confirm_delete_selected'))
            ->modalSubmitActionLabel(__('ycookies.script_scanner.delete_selected'))
            ->action(fn () => $this->deleteSelectedHistoryScans());
    }

    /**
     * Delete a specific scan from history.
     */
    public function deleteScan(int $scanId): void
    {
        $scan = ScanResult::find($scanId);
        $expectedDomainId = $this->selectedDomainId === 'custom' ? null : (int) $this->selectedDomainId;
        if (! $scan || $scan->domain_id !== $expectedDomainId) {
            return;
        }

        $scan->delete();

        // If we were viewing this scan, reset
        if ($this->viewingScanId === $scanId) {
            $this->resetResults();
        }

        // Reload history
        if ($this->selectedDomainId === 'custom') {
            $this->updatedSelectedDomainId('custom');
        } else {
            $domain = Domain::find($this->selectedDomainId);
            if ($domain) {
                $this->loadScanHistory($domain);
            }
        }

        Notification::make()->success()->title('Scan deleted')->send();
    }

    /**
     * Whether every scan in the current history list is selected.
     */
    public function allHistoryScansSelected(): bool
    {
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $this->scanHistory);
        if (empty($ids)) {
            return false;
        }

        $selected = array_map('intval', $this->selectedHistoryScanIds);

        foreach ($ids as $id) {
            if (! in_array($id, $selected, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Select all visible history rows or clear selection.
     */
    public function toggleSelectAllHistoryScans(): void
    {
        if ($this->scanHistory === []) {
            $this->selectedHistoryScanIds = [];

            return;
        }

        if ($this->allHistoryScansSelected()) {
            $this->selectedHistoryScanIds = [];
        } else {
            $this->selectedHistoryScanIds = array_values(array_map(
                static fn (array $s): int => (int) $s['id'],
                $this->scanHistory,
            ));
        }
    }

    /**
     * Delete all scans currently selected via checkboxes.
     */
    public function deleteSelectedHistoryScans(): void
    {
        if ($this->selectedHistoryScanIds === []) {
            Notification::make()
                ->warning()
                ->title('No scans selected')
                ->send();

            return;
        }

        $expectedDomainId = $this->selectedDomainId === 'custom' ? null : (int) $this->selectedDomainId;
        $ids = array_map('intval', $this->selectedHistoryScanIds);

        $query = ScanResult::query()->whereIn('id', $ids);
        if ($expectedDomainId === null) {
            $query->whereNull('domain_id');
        } else {
            $query->where('domain_id', $expectedDomainId);
        }

        $toDelete = $query->pluck('id')->all();
        if ($toDelete === []) {
            Notification::make()
                ->warning()
                ->title('No matching scans')
                ->send();

            return;
        }

        $viewingDeleted = $this->viewingScanId !== null && in_array($this->viewingScanId, $toDelete, true);
        ScanResult::query()->whereIn('id', $toDelete)->delete();

        if ($viewingDeleted) {
            $this->resetResults();
        }

        $this->selectedHistoryScanIds = [];

        if ($this->selectedDomainId === 'custom') {
            $this->updatedSelectedDomainId('custom');
        } else {
            $domain = Domain::find($this->selectedDomainId);
            if ($domain) {
                $this->loadScanHistory($domain);
            }
        }

        $n = count($toDelete);
        Notification::make()
            ->success()
            ->title('Scans deleted')
            ->body($n === 1 ? '1 scan deleted.' : "{$n} scans deleted.")
            ->send();
    }

    /**
     * Current scheduler form field values (falls back to Livewire state if the form is not ready).
     *
     * @return array<string, mixed>
     */
    protected function getSchedulerFormState(): array
    {
        try {
            return $this->form->getState();
        } catch (\Throwable) {
            return $this->data;
        }
    }

    /**
     * Rich text for "Last scan" / "Next scan" in scheduler settings (uses domain.last_scan_at like RunAutoScans).
     */
    protected function schedulerScanStatusHtml(bool $next): HtmlString
    {
        if (! $this->selectedDomainId || $this->selectedDomainId === 'custom') {
            $text = __('ycookies.script_scanner.scheduler_scan_na');

            return new HtmlString(
                '<div class="text-sm text-gray-500 dark:text-gray-400">'.e($text).'</div>'
            );
        }

        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return new HtmlString('<div class="text-sm text-gray-500 dark:text-gray-400">—</div>');
        }

        $state = $this->getSchedulerFormState();
        $tz = config('app.timezone') ?: 'UTC';

        if (! $next) {
            $last = $domain->last_scan_at;
            if (! $last) {
                $primary = __('ycookies.script_scanner.scheduler_never_scanned');

                return new HtmlString(
                    '<div class="space-y-0.5"><div class="text-sm font-medium text-gray-950 dark:text-white">'
                    .e($primary).'</div></div>'
                );
            }

            $last = $last->copy()->timezone($tz);
            $primary = $last->diffForHumans();
            $secondary = __('ycookies.script_scanner.scheduler_at_local', ['time' => $last->format('Y-m-d H:i')]);

            return new HtmlString(
                '<div class="space-y-0.5"><div class="text-sm font-medium text-gray-950 dark:text-white">'
                .e($primary).'</div><div class="text-xs text-gray-500 dark:text-gray-400">'
                .e($secondary).'</div></div>'
            );
        }

        $enabled = (bool) ($state['schedulerEnabled'] ?? false);
        if (! $enabled) {
            $primary = __('ycookies.script_scanner.scheduler_next_disabled');

            return new HtmlString(
                '<div class="space-y-0.5"><div class="text-sm font-medium text-gray-950 dark:text-white">'
                .e($primary).'</div></div>'
            );
        }

        $lockMinutes = max(60, (int) ($state['lockMinutes'] ?? $domain->lock_minutes ?? 60));
        $maxPerDay = (int) ($state['maxScansPerDay'] ?? $domain->max_scans_per_day ?? 10);

        $todayCount = ScanResult::query()
            ->where('domain_id', $domain->id)
            ->whereDate('scanned_at', today())
            ->count();

        if ($todayCount >= $maxPerDay) {
            $primary = __('ycookies.script_scanner.scheduler_next_daily_cap', [
                'used' => $todayCount,
                'max' => $maxPerDay,
            ]);

            return new HtmlString(
                '<div class="space-y-0.5"><div class="text-sm font-medium text-gray-950 dark:text-white">'
                .e($primary).'</div><div class="text-xs text-gray-500 dark:text-gray-400">'
                .e(__('ycookies.script_scanner.scheduler_next_daily_cap_hint')).'</div></div>'
            );
        }

        $last = $domain->last_scan_at;
        if (! $last) {
            $primary = __('ycookies.script_scanner.scheduler_next_eligible_now');

            return new HtmlString(
                '<div class="space-y-0.5"><div class="text-sm font-medium text-gray-950 dark:text-white">'
                .e($primary).'</div><div class="text-xs text-gray-500 dark:text-gray-400">'
                .e(__('ycookies.script_scanner.scheduler_next_eligible_hint')).'</div></div>'
            );
        }

        $earliest = $last->copy()->addMinutes($lockMinutes)->timezone($tz);

        if (now()->gte($last->copy()->addMinutes($lockMinutes))) {
            $primary = __('ycookies.script_scanner.scheduler_next_ready');

            return new HtmlString(
                '<div class="space-y-0.5"><div class="text-sm font-medium text-gray-950 dark:text-white">'
                .e($primary).'</div><div class="text-xs text-gray-500 dark:text-gray-400">'
                .e(__('ycookies.script_scanner.scheduler_next_ready_hint')).'</div></div>'
            );
        }

        $primary = __('ycookies.script_scanner.scheduler_next_in', ['when' => $earliest->diffForHumans()]);
        $secondary = __('ycookies.script_scanner.scheduler_next_earliest_at', [
            'time' => $earliest->format('Y-m-d H:i'),
        ]);

        return new HtmlString(
            '<div class="space-y-0.5"><div class="text-sm font-medium text-gray-950 dark:text-white">'
            .e($primary).'</div><div class="text-xs text-gray-500 dark:text-gray-400">'
            .e($secondary).'</div></div>'
        );
    }

    /**
     * Save scheduler settings back to the domain.
     */
    public function saveSchedulerSettings(): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $formData = $this->form->getState();

        $domain->update([
            'scheduler_enabled' => $formData['schedulerEnabled'] ?? false,
            'scheduler_mode' => $formData['schedulerMode'] ?? 'traffic',
            'lock_minutes' => max(60, $formData['lockMinutes'] ?? 60),
            'max_scans_per_day' => $formData['maxScansPerDay'] ?? 10,
            'webcron_token' => $formData['webcronToken'] ?? '',
        ]);

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->body("Scheduler settings updated for {$domain->name}")
            ->send();
    }

    public function getTitle(): string
    {
        return __('ycookies.script_scanner.page_title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Scheduler Settings')
                    ->description('Configure automatic background scanning for the selected domain.')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        Toggle::make('schedulerEnabled')
                            ->label('Auto-Scan Enabled')
                            ->helperText('Automatically scan this domain on a schedule.')
                            ->live()
                            ->default(true),
                        Select::make('schedulerMode')
                            ->label('Scan Mode')
                            ->options([
                                'traffic' => 'Traffic-Triggered (Recommended)',
                                'scheduler' => 'Laravel Scheduler',
                                'cronless' => 'Cronless PHP Loop',
                                'webcron' => 'Web Cron (External Ping)',
                            ])
                            ->default('traffic')
                            ->live()
                            ->hidden(fn (Get $get) => ! $get('schedulerEnabled')),

                        // Mode description
                        \Filament\Forms\Components\Placeholder::make('modeDescription')
                            ->label('')
                            ->content(function (Get $get) {
                                return match ($get('schedulerMode')) {
                                    'traffic' => '💡 Scans run automatically when visitors access your site. The scanner checks if enough time has passed since the last scan, and if so, triggers a background scan. Best for most websites.',
                                    'scheduler' => '💡 Runs according to the Laravel scheduler (e.g., hourly). Best for high-traffic sites where you want precisely timed scans regardless of traffic.',
                                    'cronless' => '💡 A background PHP process periodically checks and runs scans without needing an external cron job. Best for shared hosting without cron access.',
                                    'webcron' => '💡 An external cron service pings a URL on your server to trigger scans. Setup: 1) Copy the Ping URL below, 2) Add it to your cron service (e.g., cron-job.org, EasyCron, or your hosting panel), 3) Set your desired interval (e.g., every 60 minutes).',
                                    default => '',
                                };
                            })
                            ->hidden(fn (Get $get) => ! $get('schedulerEnabled')),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('lockMinutes')
                                    ->label('Min Interval (Minutes)')
                                    ->helperText('Wait at least this many minutes between scans. Minimum 60 minutes (1 hour) to prevent server overload.')
                                    ->numeric()
                                    ->minValue(60)
                                    ->maxValue(1440)
                                    ->default(60)
                                    ->hidden(fn (Get $get) => ! in_array($get('schedulerMode'), ['traffic', 'scheduler', 'cronless']) || ! $get('schedulerEnabled')),
                                TextInput::make('maxScansPerDay')
                                    ->label('Max Scans / Day')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->default(10)
                                    ->helperText('Safety limit to control server load.')
                                    ->hidden(fn (Get $get) => ! $get('schedulerEnabled')),
                            ]),

                        // WebCron-specific fields
                        Grid::make(2)
                            ->schema([
                                TextInput::make('webcronToken')
                                    ->label('WebCron Token')
                                    ->helperText('A secret token to secure your cron URL. Change this if compromised.'),
                                \Filament\Forms\Components\Placeholder::make('webcronUrl')
                                    ->label('Ping URL (copy this)')
                                    ->content(fn () => $this->selectedDomainId
                                        ? url('/cron/run-scheduler?token='.($this->data['webcronToken'] ?? ''))
                                        : 'Select a domain first'),
                            ])
                            ->hidden(fn (Get $get) => $get('schedulerMode') !== 'webcron' || ! $get('schedulerEnabled')),

                        Grid::make(2)
                            ->schema([
                                \Filament\Forms\Components\Placeholder::make('lastScanInfo')
                                    ->label(__('ycookies.script_scanner.scheduler_last_scan'))
                                    ->content(fn () => $this->schedulerScanStatusHtml(false)),
                                \Filament\Forms\Components\Placeholder::make('nextScanInfo')
                                    ->label(__('ycookies.script_scanner.scheduler_next_scan'))
                                    ->content(fn () => $this->schedulerScanStatusHtml(true)),
                            ]),
                    ])
                    ->footerActions([
                        \Filament\Actions\Action::make('saveSettings')
                            ->label('Save Settings')
                            ->icon('heroicon-m-check')
                            ->action(fn () => $this->saveSchedulerSettings()),
                    ]),
            ])
            ->statePath('data');
    }

    public function getDomains(): array
    {
        return Domain::pluck('name', 'id')->toArray();
    }

    /**
     * Step 1: Start scan — discover pages, set up progress tracking.
     */
    /**
     * Start a "baseline" scan — scans the website without categorizing
     * against installed blockers. Shows what the raw site loads so you
     * can see the page as if no consent banner / blockers existed.
     */
    public function scanBareDomain(): void
    {
        $this->isBareMode = true;
        $this->scanDomain();
    }

    public function scanDomain(): void
    {
        if (! $this->selectedDomainId) {
            Notification::make()->warning()->title('Select a domain')->body('Please select a domain or custom domain to scan.')->send();

            return;
        }

        if ($this->selectedDomainId === 'custom') {
            if (empty(trim($this->customDomainUrl))) {
                Notification::make()->warning()->title('URL Required')->body('Please enter a custom domain URL.')->send();

                return;
            }
            $baseUrl = 'https://'.ltrim(parse_url(trim($this->customDomainUrl), PHP_URL_HOST) ?? trim($this->customDomainUrl), 'https://');
            $domainHost = parse_url($baseUrl, PHP_URL_HOST);
            $this->scannedDomainName = $domainHost;
        } else {
            $domain = Domain::find($this->selectedDomainId);
            if (! $domain) {
                return;
            }
            $baseUrl = 'https://'.ltrim($domain->name, 'https://');
            $domainHost = parse_url($baseUrl, PHP_URL_HOST);
            $this->scannedDomainName = $domain->name;

            // Enforce monthly limit
            $group = $domain->group;
            if ($group && ! $group->canRunScan()) {
                Notification::make()
                    ->danger()
                    ->title('Scan limit reached')
                    ->body("You have reached your monthly scan limit ({$group->scan_limit}). Please upgrade your plan to scan more domains.")
                    ->send();

                return;
            }
        }

        // Reset all state (bare mode is set before calling scanDomain, don't reset it here)
        $this->isScanning = true;
        $this->hasResults = false;
        $this->scanError = null;
        $this->scanProgress = 0;
        $this->scanCurrentPage = $this->isBareMode ? 'Baseline scan — discovering pages...' : 'Discovering pages...';
        $this->scanPageIndex = 0;
        $this->scanLog = [];
        $this->pageScriptsMap = [];
        $this->allCollectedUrls = [];
        $this->allCollectedUnblockedUrls = [];
        $this->protectedScripts = [];
        $this->suggestedScripts = [];
        $this->unknownScripts = [];
        $this->rawScripts = [];
        $this->scanStages = [];
        $this->discoveredPages = [];

        try {
            // Parse manual pages from textarea
            $manualPages = [];
            if (trim($this->manualPagesInput)) {
                $manualPages = array_filter(
                    array_map('trim', explode("\n", $this->manualPagesInput))
                );
            }

            if (! empty($manualPages)) {
                // Use manual pages (max 500)
                $pages = array_slice($manualPages, 0, 500);
                $pages = array_map(function ($page) use ($baseUrl) {
                    $page = trim($page);
                    if (str_starts_with($page, '/')) {
                        return rtrim($baseUrl, '/').$page;
                    }
                    if (! str_starts_with($page, 'http')) {
                        return rtrim($baseUrl, '/').'/'.ltrim($page, '/');
                    }

                    return $page;
                }, $pages);
                $pages = array_values(array_filter($pages));
                $this->scanStages['discovery'] = ['status' => 'success', 'count' => count($pages), 'source' => 'manual', 'total_discovered' => count($pages)];
            } else {
                // Auto-discover (smart sampling happens inside discoverPages)
                $pages = ScriptScannerService::discoverPages($baseUrl, $domainHost);
                $this->scanStages['discovery'] = ['status' => 'success', 'count' => count($pages), 'source' => 'auto', 'total_discovered' => count($pages)];
            }

            $this->pagesToScan = $pages;
            $this->discoveredPages = $pages;
            $this->scanCurrentPage = count($pages).' pages found — starting scan...';

        } catch (\Exception $e) {
            $this->pagesToScan = [$baseUrl];
            $this->discoveredPages = [$baseUrl];
            $this->scanStages['discovery'] = ['status' => 'error', 'error' => $e->getMessage(), 'count' => 1];
            $this->scanCurrentPage = 'Discovery failed, scanning homepage...';
        }
    }

    /**
     * Step 2: Scan the next page in queue (called by wire:poll).
     */
    public function scanNextPage(): void
    {
        if (! $this->isScanning || empty($this->pagesToScan)) {
            return;
        }

        $total = count($this->pagesToScan);

        // All pages scanned?
        if ($this->scanPageIndex >= $total) {
            $this->finalizeScan();

            return;
        }

        $pageUrl = $this->pagesToScan[$this->scanPageIndex];

        if ($this->selectedDomainId === 'custom') {
            $baseUrl = 'https://'.$this->scannedDomainName;
            $domainHost = $this->scannedDomainName;
        } else {
            $domain = Domain::find($this->selectedDomainId);
            if (! $domain) {
                $this->finalizeScan();

                return;
            }
            $baseUrl = 'https://'.ltrim($domain->name, 'https://');
            $domainHost = parse_url($baseUrl, PHP_URL_HOST);
        }

        // Update progress
        $this->scanCurrentPage = $pageUrl;
        $this->scanProgress = intval(($this->scanPageIndex / $total) * 100);

        // Scan this page
        $pageResult = ['url' => $pageUrl, 'status' => 'success', 'scripts' => 0, 'time' => 0];
        $startTime = microtime(true);

        try {
            $unblockedOut = [];
            $httpUrls = ScriptScannerService::httpScan($pageUrl, $domainHost, $unblockedOut);
            $this->allCollectedUrls = array_merge($this->allCollectedUrls, $httpUrls);
            $this->allCollectedUnblockedUrls = array_merge($this->allCollectedUnblockedUrls, $unblockedOut);
            $pageResult['scripts'] = count($httpUrls);
            $pageResult['time'] = round(microtime(true) - $startTime, 2);
        } catch (\Exception $e) {
            $pageResult['status'] = 'error';
            $pageResult['error'] = $e->getMessage();
            $pageResult['time'] = round(microtime(true) - $startTime, 2);
        }

        $this->scanLog[] = $pageResult;
        $this->pageScriptsMap[$pageUrl] = $pageResult;
        $this->scanPageIndex++;

        // If this was the last page, finalize on the next tick
        if ($this->scanPageIndex >= $total) {
            $this->scanProgress = 100;
            $this->scanCurrentPage = 'Analyzing results...';
        }
    }

    /**
     * Finalize the scan: categorize scripts, send report, update domain.
     */
    protected function finalizeScan(): void
    {
        $domain = null;
        if ($this->selectedDomainId !== 'custom') {
            $domain = Domain::find($this->selectedDomainId);
            if (! $domain) {
                return;
            }
        }

        $baseUrl = 'https://'.$this->scannedDomainName;
        $domainHost = $this->scannedDomainName;

        // Deduplicate
        $allUrls = array_unique($this->allCollectedUrls);
        $httpCount = count($allUrls);

        $this->scanStages['http'] = [
            'status' => 'success',
            'count' => $httpCount,
            'pages_scanned' => count($this->pagesToScan),
        ];

        // Try deep Chrome scan on homepage only
        try {
            $deepUrls = ScriptScannerService::deepScan($baseUrl, $domainHost);
            $allUrls = array_unique(array_merge($allUrls, $deepUrls));
            $this->scanStages['deep'] = ['status' => 'success', 'count' => count($deepUrls)];
        } catch (\Exception $e) {
            $this->scanStages['deep'] = ['status' => 'skipped', 'count' => 0, 'error' => $e->getMessage()];
        }

        // Categorize — in bare mode, skip blocker matching entirely.
        // Every script goes into "unknown" so the user sees the raw picture.
        if ($this->isBareMode) {
            // Build a flat "all unknown" list grouped by host
            $seenHosts = [];
            $unknown = [];
            foreach ($allUrls as $srcUrl) {
                $host = parse_url($srcUrl, PHP_URL_HOST) ?? 'unknown';
                if (! isset($seenHosts[$host])) {
                    $unknown[] = ['url' => $srcUrl, 'host' => $host];
                    $seenHosts[$host] = true;
                }
            }
            $categorized = [
                'protected' => [],
                'suggested' => [],
                'unknown' => $unknown,
                'raw' => $allUrls,
            ];
        } else {
            $categorized = ScriptScannerService::categorize($domain, $allUrls);
        }

        $this->protectedScripts = $categorized['protected'];
        $this->suggestedScripts = $categorized['suggested'];
        $this->unknownScripts = $categorized['unknown'];
        $this->unblockedScripts = array_values(array_unique(array_merge(
            $categorized['unblocked'] ?? [],
            $this->allCollectedUnblockedUrls
        )));
        $this->rawScripts = $categorized['raw'];
        $this->hasResults = true;
        $this->isScanning = false;
        $this->scanProgress = 100;
        $this->scanCurrentPage = '';

        $totalFound = count($categorized['raw']);
        $pagesScanned = count($this->pagesToScan);

        // Determine scan source tag
        $scanSource = $this->isBareMode
            ? 'baseline'
            : (! empty(array_filter(array_map('trim', explode("\n", $this->manualPagesInput)))) ? 'manual' : 'auto');

        // Create a ScanResult record (append to scan history)
        $scanResult = ScanResult::create([
            'domain_id' => $domain ? $domain->id : null,
            'domain_name' => $this->scannedDomainName,
            'scanned_at' => now(),
            'source' => $scanSource,
            'total_scripts' => $totalFound,
            'protected_count' => count($this->protectedScripts),
            'suggested_count' => count($this->suggestedScripts),
            'unknown_count' => count($this->unknownScripts),
            'unblocked_count' => count($this->unblockedScripts),
            'pages_scanned_count' => $pagesScanned,
            'pages_scanned' => $this->discoveredPages,
            'scan_log' => $this->scanLog,
            'scan_stages' => $this->scanStages,
            'protected_scripts' => $this->protectedScripts,
            'suggested_scripts' => $this->suggestedScripts,
            'unknown_scripts' => $this->unknownScripts,
            'unblocked_scripts' => $this->unblockedScripts,
            'raw_scripts' => $this->rawScripts,
        ]);

        // Update domain last_scan_at
        if ($domain) {
            $domain->update(['last_scan_at' => now()]);
            OutboundWebhookDispatcher::dispatchScanCompleted($domain, $scanResult);
        }

        // Set currently viewing this new scan
        $this->viewingScanId = $scanResult->id;

        // Reload scan history to include this new scan
        if ($domain) {
            $this->selectedHistoryScanIds = [];
            $this->scanHistory = ScanResult::where('domain_id', $domain->id)
                ->orderByDesc('scanned_at')
                ->limit(50)
                ->get(['id', 'domain_name', 'scanned_at', 'source', 'total_scripts', 'protected_count', 'suggested_count', 'unknown_count', 'unblocked_count', 'pages_scanned_count'])
                ->toArray();
        } else {
            $this->updatedSelectedDomainId('custom');
        }

        $notifTitle = $this->isBareMode ? 'Baseline scan complete' : 'Scan complete';
        $notifBody = $this->isBareMode
            ? "Baseline scan: {$pagesScanned} pages — {$totalFound} external resources found (no blockers applied)"
            : "Scanned {$pagesScanned} pages — found {$totalFound} external scripts on {$this->scannedDomainName}";

        Notification::make()
            ->success()
            ->title($notifTitle)
            ->body($notifBody)
            ->send();

        // Reset bare mode flag
        $this->isBareMode = false;

        // Send email report if enabled
        if ($domain) {
            $results = [
                'protected' => $this->protectedScripts,
                'suggested' => $this->suggestedScripts,
                'unknown' => $this->unknownScripts,
                'raw' => $this->rawScripts,
                'stages' => $this->scanStages,
                'discoveredPages' => $this->discoveredPages,
            ];
            $this->maybeSendScanReport($domain, $results);
        }
    }

    /**
     * Save manually entered pages to the domain.
     */
    public function saveManualPages(): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $pages = array_filter(
            array_map('trim', explode("\n", $this->manualPagesInput))
        );

        // Limit to 500
        $pages = array_slice($pages, 0, 500);

        $domain->update(['scan_pages' => $pages]);

        Notification::make()
            ->success()
            ->title('Pages saved')
            ->body(count($pages).' custom pages saved for '.$domain->name)
            ->send();
    }

    /**
     * Save report email settings.
     */
    public function saveReportSettings(): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $domain->update([
            'report_email' => $this->reportEmail ?: null,
            'report_enabled' => $this->reportEnabled,
        ]);

        Notification::make()
            ->success()
            ->title('Report settings saved')
            ->body($this->reportEnabled ? 'Scan reports will be sent to '.$this->reportEmail : 'Email reports disabled')
            ->send();
    }

    /**
     * Send scan report via email if enabled.
     */
    protected function maybeSendScanReport(Domain $domain, array $results): void
    {
        if (! $domain->report_enabled || ! $domain->report_email) {
            return;
        }

        try {
            \App\Services\NotificationService::configureDynamicMailer();

            $html = ScriptScannerService::generateScanReportHtml($domain, $results);
            $subject = "🔍 Scan Report: {$domain->name} — ".now()->format('M d, Y');

            Mail::html($html, function ($message) use ($subject, $domain) {
                $smtp = \App\Models\SmtpSetting::instance();
                $message->to($domain->report_email)
                    ->subject($subject)
                    ->from($smtp->from_address ?: 'noreply@ycookies.test', $smtp->from_name ?: 'YCookies Scanner');
            });

            Notification::make()
                ->success()
                ->title('Report emailed')
                ->body('Scan report sent to '.$domain->report_email)
                ->duration(5000)
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->warning()
                ->title('Report email failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Install a suggested script blocker from the library.
     */
    public function installBlocker(string $templateKey): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $templates = TemplateLibraryService::getTemplates();
        $tpl = $templates[$templateKey] ?? null;
        if (! $tpl) {
            Notification::make()->danger()->title('Template not found')->send();

            return;
        }

        // Check if script blocker already exists
        $existingKey = $tpl['key'] ?? $templateKey;
        if (ScriptBlocker::where('domain_id', $domain->id)->where('key', $existingKey)->exists()) {
            Notification::make()->warning()->title('Already installed')->body("{$tpl['name']} blocker already exists.")->send();

            return;
        }

        // Get handles + phrases depending on template type
        $handles = $tpl['handles'] ?? ($tpl['script_blocker']['handles'] ?? []);
        $phrases = $tpl['phrases'] ?? ($tpl['script_blocker']['phrases'] ?? []);

        ScriptBlocker::create([
            'domain_id' => $domain->id,
            'key' => $existingKey,
            'name' => $tpl['name'],
            'is_active' => true,
            'handles' => $handles,
            'phrases' => $phrases,
            'group_id' => $domain->group_id,
            'template_key' => $templateKey,
            'template_version' => $tpl['version'] ?? '1.0.0',
        ]);

        Notification::make()
            ->success()
            ->title('Script Blocker installed')
            ->body("{$tpl['name']} is now blocking scripts on {$domain->name}")
            ->send();

        // Move from suggested to protected in UI
        $this->suggestedScripts = array_filter($this->suggestedScripts, fn ($s) => $s['template_key'] !== $templateKey);
        $this->suggestedScripts = array_values($this->suggestedScripts);

        // Re-scan to refresh categories
        $this->scanDomain();
    }

    /**
     * Create a custom script blocker for an unknown script.
     */
    public function createCustomBlocker(string $scriptUrl, string $name, string $purpose): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain) {
            return;
        }

        $host = parse_url($scriptUrl, PHP_URL_HOST) ?? 'custom';
        $key = 'custom-'.\Illuminate\Support\Str::slug($host);

        // Check for duplicate
        if (ScriptBlocker::where('domain_id', $domain->id)->where('key', $key)->exists()) {
            Notification::make()->warning()->title('Already exists')->body("A blocker for {$host} already exists.")->send();

            return;
        }

        ScriptBlocker::create([
            'domain_id' => $domain->id,
            'key' => $key,
            'name' => $name ?: $host,
            'is_active' => true,
            'handles' => [$key],
            'phrases' => [$host],
            'group_id' => $domain->group_id,
        ]);

        Notification::make()
            ->success()
            ->title('Custom blocker created')
            ->body("Now blocking scripts from {$host}")
            ->send();

        // Re-scan
        $this->scanDomain();
    }

    /**
     * Send a report of unknown scripts to ycookies@ypsilon.dev.
     * If SMTP is configured, send via email. Otherwise, return mailto link.
     */
    public function sendReport(): void
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain || empty($this->unknownScripts)) {
            Notification::make()->warning()->title('Nothing to report')->body('No unknown scripts to report.')->send();

            return;
        }

        $reportBody = ScriptScannerService::generateReportBody($domain, $this->unknownScripts);
        $subject = "YCookies Script Report: {$domain->name}";
        $smtp = SmtpSetting::instance();

        if ($smtp->is_active && $smtp->host) {
            // Try to send via SMTP
            try {
                \App\Services\NotificationService::configureDynamicMailer();

                Mail::raw($reportBody, function ($message) use ($subject, $smtp) {
                    $message->to('ycookies@ypsilon.dev')
                        ->subject($subject)
                        ->from($smtp->from_address ?: 'noreply@ycookies.test', $smtp->from_name ?: 'YCookies');
                });

                // Create bell notification
                $this->createReportNotification($domain, 'sent');

                Notification::make()
                    ->success()
                    ->title('Report sent!')
                    ->body('Your report has been emailed to ycookies@ypsilon.dev. Please mark the notification as done after review.')
                    ->duration(8000)
                    ->send();

                return;
            } catch (\Exception $e) {
                // Fall through to mailto
                Notification::make()
                    ->warning()
                    ->title('SMTP failed — use manual email')
                    ->body('Could not send via SMTP: '.$e->getMessage())
                    ->send();
            }
        }

        // Fallback: create notification with mailto instructions
        $this->createReportNotification($domain, 'pending');

        Notification::make()
            ->warning()
            ->title('SMTP not configured')
            ->body('Please send the report manually to ycookies@ypsilon.dev. Check the notification bell for the email template.')
            ->duration(10000)
            ->send();
    }

    /**
     * Create a bell notification for the report status.
     */
    protected function createReportNotification(Domain $domain, string $status): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $reportBody = ScriptScannerService::generateReportBody($domain, $this->unknownScripts);
        $subject = "YCookies Script Report: {$domain->name}";
        $scriptCount = count($this->unknownScripts);

        // Build mailto link
        $mailtoBody = rawurlencode($reportBody);
        $mailtoSubject = rawurlencode($subject);
        $mailtoLink = "mailto:ycookies@ypsilon.dev?subject={$mailtoSubject}&body={$mailtoBody}";

        $title = $status === 'sent'
            ? "Script report sent for {$domain->name}"
            : "Send script report for {$domain->name}";

        $body = $status === 'sent'
            ? "{$scriptCount} unknown scripts reported. Mark as done when reviewed."
            : "{$scriptCount} unknown scripts detected. Send the report via email and mark as done.";

        \Filament\Notifications\Notification::make()
            ->warning()
            ->icon('heroicon-o-document-text')
            ->title($title)
            ->body($body)
            ->actions([
                \Filament\Actions\Action::make('send_email')
                    ->label($status === 'sent' ? 'Re-send Email' : 'Open Email')
                    ->url($mailtoLink)
                    ->openUrlInNewTab()
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    /**
     * Get the mailto URL for manual sending.
     */
    public function getMailtoUrl(): ?string
    {
        $domain = Domain::find($this->selectedDomainId);
        if (! $domain || empty($this->unknownScripts)) {
            return null;
        }

        $reportBody = ScriptScannerService::generateReportBody($domain, $this->unknownScripts);
        $subject = "YCookies Script Report: {$domain->name}";

        return 'mailto:ycookies@ypsilon.dev?subject='.rawurlencode($subject).'&body='.rawurlencode($reportBody);
    }
}







