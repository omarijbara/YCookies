<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int|null $group_id
 * @property int|null $cookie_bar_id
 */
class Domain extends Model
{
    use HasFactory;

    public const AUTO_BLOCKING_DEFAULTS = [
        'content' => true,
        'script' => true,
        'style' => true,
        'service' => true,
    ];
    
    protected $fillable = [
        'name',
        'site_id',
        'group_id',
        'cookie_bar_id',
        'is_active',
        'cross_domain_enabled',
        'theme_settings',
        'localization',
        'translations',
        'scan_frequency',
        'last_scanned_at',
        'tcm_config',
        'geo_rules',
        'ui_config',
        'tcf_config',
        'cmp_id',
        'scheduler_mode',
        'scheduler_enabled',
        'lock_minutes',
        'max_scans_per_day',
        'last_scan_at',
        'webcron_token',
        'geo_restriction_eu',
        'geo_skipped_countries',
        'consent_version',
        'consent_mode_enabled',
        'advanced_consent_mode',
        'consent_mode_mapping',
        'scan_pages',
        'report_email',
        'report_enabled',
        'last_scan_results',
        'priority_pages',
        'auto_priority_pages',
        'discovered_pages_count',
        'current_set_index',
        'current_cycle',
        'last_discovery_at',
        'origin_subdomain',
        'origin_auth_token',
        'origin_auth_token_legacy',
        'origin_auth_legacy_expires_at',
        'origin_url',
        'origin_ip',
        'origin_host',
        'proxy_enabled',
        'proxy_engine',
        'proxy_status',
        'rate_limit_enabled',
        'rate_limit_max_requests_per_minute',
        'rate_limit_exclude_paths',
        'auto_blocking',
        'fallback_content_blocker_id',
        'proxy_verified_at',

        'config_version',
        'health_check_enabled',
        'health_check_mode',
        'health_status',
        'last_health_check_at',
        'health_check_interval_minutes',
        'health_check_max_per_day',
        'health_check_overrides',
        'last_health_success_at',
        'health_consecutive_failures',

        'active_revision_id',
        'manifest_enabled',
    ];

    protected static function booted()
    {
        static::creating(function ($domain) {
            if (empty($domain->origin_auth_token)) {
                $domain->origin_auth_token = \Illuminate\Support\Str::random(40);
            }
        });

        // Auto-detect IP addresses in origin_url and promote to origin_ip.
        // When origin_url is "https://93.184.216.34", the proxy would connect
        // directly without TLS SNI, causing cert mismatch. Moving the IP to
        // origin_ip lets the proxy use getOrCreateAgent() with correct SNI.
        static::saving(function ($domain) {
            if ($domain->isDirty('origin_url') && $domain->origin_url) {
                // Auto-prepend https:// if no scheme is present (bare IP or domain)
                if (!preg_match('#^https?://#i', $domain->origin_url)) {
                    $domain->origin_url = 'https://' . ltrim($domain->origin_url, '/');
                }
                $parsed = parse_url($domain->origin_url);
                $host = $parsed['host'] ?? '';

                if ($host && filter_var($host, FILTER_VALIDATE_IP)) {
                    $domain->origin_ip = $host;
                    $domain->origin_url = null;

                    // Auto-set origin_host to domain name if not already set,
                    // so the proxy sends the correct Host header and TLS SNI.
                    if (empty($domain->origin_host)) {
                        $domain->origin_host = $domain->name;
                    }
                }
            }
        });

        static::addGlobalScope('tenant', function (\Illuminate\Database\Eloquent\Builder $query) {
            // Use Filament's active tenant when available (admin panel context).
            // In API/CLI/queue contexts, no scope is applied — tenant isolation
            // must be enforced at the controller/job level via explicit group_id.
            if (class_exists(\Filament\Facades\Filament::class)) {
                $tenant = \Filament\Facades\Filament::getTenant();
                if ($tenant) {
                    $query->where('group_id', $tenant->getKey());
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'cross_domain_enabled' => 'boolean',
            'theme_settings' => 'array',
            'localization' => 'array',
            'translations' => 'array',
            'last_scanned_at' => 'datetime',
            'tcm_config' => 'array',
            'geo_rules' => 'array',
            'ui_config' => 'array',
            'tcf_config' => 'array',
            'scheduler_enabled' => 'boolean',
            'last_scan_at' => 'datetime',
            'geo_restriction_eu' => 'boolean',
            'geo_skipped_countries' => 'array',
            'consent_version' => 'integer',
            'consent_mode_enabled' => 'boolean',
            'advanced_consent_mode' => 'boolean',
            'consent_mode_mapping' => 'array',
            'scan_pages' => 'array',
            'report_enabled' => 'boolean',
            'last_scan_results' => 'array',
            'priority_pages' => 'array',
            'auto_priority_pages' => 'array',
            'last_discovery_at' => 'datetime',
            'proxy_enabled' => 'boolean',
            'proxy_verified_at' => 'datetime',
            'rate_limit_enabled' => 'boolean',
            'rate_limit_max_requests_per_minute' => 'integer',
            'rate_limit_exclude_paths' => 'array',
            'auto_blocking' => 'array',

            'config_version' => 'integer',
            'origin_auth_legacy_expires_at' => 'datetime',
            'health_check_enabled' => 'boolean',
            'last_health_check_at' => 'datetime',
            'health_check_overrides' => 'array',
            'last_health_success_at' => 'datetime',
        ];
    }

    public function cookieBar(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CookieBar::class);
    }

    public function cookieGroups(): BelongsToMany
    {
        return $this->belongsToMany(CookieGroup::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    public function contentBlockers(): HasMany
    {
        return $this->hasMany(ContentBlocker::class);
    }

    public function fallbackContentBlocker(): BelongsTo
    {
        return $this->belongsTo(ContentBlocker::class, 'fallback_content_blocker_id');
    }

    public function scriptBlockers(): HasMany
    {
        return $this->hasMany(ScriptBlocker::class);
    }

    public function styleBlockers(): HasMany
    {
        return $this->hasMany(ScriptBlocker::class)->where('blocker_type', 'style');
    }

    public function getAutoBlockingConfig(): array
    {
        $stored = $this->auto_blocking;
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_replace(self::AUTO_BLOCKING_DEFAULTS, array_intersect_key($stored, self::AUTO_BLOCKING_DEFAULTS));
    }

    public function consentLogs(): HasMany
    {
        return $this->hasMany(ConsentLog::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function discoveredResources(): HasMany
    {
        return $this->hasMany(DiscoveredResource::class);
    }

    public function scanResults(): HasMany
    {
        return $this->hasMany(ScanResult::class)->orderByDesc('scanned_at');
    }

    public function healthCheckResults(): HasMany
    {
        return $this->hasMany(HealthCheckResult::class)->orderByDesc('checked_at');
    }

    public function pageSets(): HasMany
    {
        return $this->hasMany(DomainPageSet::class)->orderBy('set_index');
    }

    /**
     * Get the next unscanned page set for the current cycle.
     */
    public function nextPageSet(): ?DomainPageSet
    {
        return $this->pageSets()
            ->where('cycle_number', $this->current_cycle)
            ->whereNull('last_scanned_at')
            ->orderBy('set_index')
            ->first();
    }

    /**
     * Merge auto-detected + user-selected priority pages.
     */
    public function allPriorityPages(): array
    {
        return array_values(array_unique(array_merge(
            $this->auto_priority_pages ?? [],
            $this->priority_pages ?? []
        )));
    }

    /**
     * The currently active manifest revision.
     */
    public function activeRevision(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RuntimeRevision::class, 'active_revision_id');
    }

    /**
     * All manifest revisions for this domain.
     */
    public function revisions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RuntimeRevision::class)->orderByDesc('revision_number');
    }

}
