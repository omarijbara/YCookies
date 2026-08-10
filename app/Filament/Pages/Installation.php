<?php

namespace App\Filament\Pages;

use App\Models\Domain;
use App\Services\CoolifyService;
use App\Services\UrlValidator;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Installation extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket-square';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.tools');
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.system.installation');
    }

    public function getTitle(): string
    {
        return __('ycookies.system.installation');
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.installation');
    }

    protected string $view = 'filament.pages.installation';

    public ?Domain $activeDomain = null;

    public ?int $selectedDomainId = null;

    /** @var array<int, string> id => name pairs for the switcher */
    public array $domainOptions = [];

    // Method 1: Script snippets
    public string $basicEmbedCode = '';

    public string $advancedEmbedCode = '';

    // Method 2: Proxy tunnel
    public string $originIp = '';

    public string $originHost = '';

    public bool $proxyEnabled = false;

    public string $proxyStatus = '';

    public string $cnameTarget = 'proxy.ycookies.com';

    public function mount()
    {
        $tenant = Filament::getTenant();

        if ($tenant) {
            $domains = Domain::where('group_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            $this->domainOptions = $domains->pluck('name', 'id')->toArray();

            if ($domains->isNotEmpty()) {
                $this->activeDomain = $domains->first();
                $this->selectedDomainId = $this->activeDomain->id;
                $this->loadDomainData();
            }
        }
    }

    /**
     * Switch to a different domain (Livewire action).
     */
    public function updatedSelectedDomainId(?int $value): void
    {
        if (! $value) {
            return;
        }

        $this->activeDomain = Domain::find($value);

        if ($this->activeDomain) {
            $this->loadDomainData();
        }
    }

    /**
     * Load all data for the currently selected domain.
     */
    protected function loadDomainData(): void
    {
        if (! $this->activeDomain) {
            return;
        }

        $this->generateSnippets();

        // Load proxy settings
        $this->originIp = $this->activeDomain->origin_ip ?? '';
        $this->originHost = $this->activeDomain->origin_host ?? '';
        $this->proxyEnabled = $this->activeDomain->proxy_enabled ?? false;
        $this->proxyStatus = $this->activeDomain->proxy_status ?? '';
    }

    /**
     * Generate both the basic and advanced embed snippets.
     *
     * Basic: just the manager script (defer).
     * Advanced: synchronous bootstrapper (from API) + manager script (defer).
     *
     * Both scripts pull their data live from the server — no static
     * blocklists baked in. When the user updates Script Blockers in the
     * admin panel, changes are reflected within the cache TTL (5 min).
     */
    protected function generateSnippets(): void
    {
        $url = rtrim(config('app.url'), '/');
        $siteId = $this->activeDomain->site_id;

        $bootSrc = "{$url}/api/boot/{$siteId}.js";
        $scriptSrc = "{$url}/api/script/{$siteId}.js";

        // Basic: simple one-line defer script (no pre-blocking)
        $this->basicEmbedCode = '<script src="'.$scriptSrc.'" id="ycookies-manager" type="module" defer></script>';

        // Advanced: synchronous bootstrapper + defer manager
        $this->advancedEmbedCode = '<!-- YCookies Bootstrapper — blocks scripts BEFORE they execute -->'."\n"
            .'<script src="'.$bootSrc.'"></script>'."\n"
            .'<script src="'.$scriptSrc.'" id="ycookies-manager" type="module" defer></script>';
    }

    /**
     * Save proxy tunnel settings (Livewire action).
     */
    public function saveProxySettings(): void
    {
        if (! $this->activeDomain) {
            return;
        }

        // Validate origin IP if provided
        if (! empty($this->originIp)) {
            $validator = new UrlValidator;
            $result = $validator->validateOriginIp($this->originIp);
            if (! $result['valid']) {
                $this->addError('originIp', $result['error']);

                return;
            }
        }

        $wasEnabled = $this->activeDomain->proxy_enabled;
        $isNowEnabled = $this->proxyEnabled && ! empty($this->originIp);

        $this->activeDomain->update([
            'origin_ip' => $this->originIp ?: null,
            'origin_host' => $this->originHost ?: null,
            'proxy_enabled' => $isNowEnabled,
            'proxy_status' => $isNowEnabled ? ($this->activeDomain->proxy_status ?: 'pending') : null,
        ]);

        // If just enabled, immediately register with Coolify/Traefik
        if ($isNowEnabled && ! $wasEnabled) {
            $coolify = app(CoolifyService::class);
            $coolifyOk = $coolify->addDomainToApp($this->activeDomain->name, true);

            if ($coolifyOk) {
                $dnsOk = $coolify->verifyDns($this->activeDomain->name);
                $this->activeDomain->update([
                    'proxy_status' => $dnsOk ? 'active' : 'ssl_pending',
                    'proxy_verified_at' => $dnsOk ? now() : null,
                ]);

                Notification::make()
                    ->title($dnsOk ? 'Proxy Active ✅' : 'Domain Registered with Traefik ✅')
                    ->body($dnsOk
                        ? 'DNS is pointing correctly. Proxy tunnel is active!'
                        : 'Traefik is now listening for this domain. Point your DNS and SSL will be provisioned automatically.')
                    ->success()
                    ->send();
            } else {
                $this->activeDomain->update(['proxy_status' => 'pending']);
                Notification::make()
                    ->title('Registration Pending')
                    ->body('Could not register with Traefik immediately. Will be retried automatically.')
                    ->warning()
                    ->send();
            }

            $this->proxyStatus = $this->activeDomain->fresh()->proxy_status ?? 'pending';
        }

        // If disabled, remove from Coolify
        if (! $isNowEnabled && $wasEnabled) {
            $coolify = app(CoolifyService::class);
            $coolify->removeDomainFromApp($this->activeDomain->name, true);
            $this->proxyStatus = '';
        }

        \Illuminate\Support\Facades\Cache::forget("proxy_domain:{$this->activeDomain->name}");

        Notification::make()
            ->title(__('ycookies.saved'))
            ->success()
            ->send();
    }

    /**
     * Manually trigger DNS verification (Livewire action).
     */
    public function verifyDns(): void
    {
        if (! $this->activeDomain || ! $this->proxyEnabled) {
            return;
        }

        $coolify = app(CoolifyService::class);
        $dnsOk = $coolify->verifyDns($this->activeDomain->name);

        if ($dnsOk) {
            $coolifyOk = $coolify->addDomainToApp($this->activeDomain->name, true);
            $this->activeDomain->update([
                'proxy_status' => $coolifyOk ? 'active' : 'ssl_pending',
                'proxy_verified_at' => $coolifyOk ? now() : null,
            ]);
            $this->proxyStatus = $this->activeDomain->fresh()->proxy_status;

            Notification::make()
                ->title('DNS Verified ✅')
                ->body($coolifyOk ? 'SSL will be provisioned automatically.' : 'SSL provisioning in progress...')
                ->success()
                ->send();
        } else {
            $this->activeDomain->update(['proxy_status' => 'dns_error']);
            $this->proxyStatus = 'dns_error';

            Notification::make()
                ->title('DNS Not Found ❌')
                ->body("CNAME record for {$this->activeDomain->name} does not point to {$this->cnameTarget}")
                ->danger()
                ->send();
        }
    }
}
