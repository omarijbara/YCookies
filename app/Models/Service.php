<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Service extends Model
{
    use \Spatie\Translatable\HasTranslations;

    protected $fillable = [
        'name',
        'key',
        'purpose',
        'cookie_group_id',
        'group_id',
        'provider_id',
        'template_key',
        'template_version',
        'cookie_names',
        'is_active',
        'sort_order',
        // Consent Execution Registry v2
        'integration_type',
        'provider_key',
        'service_domains',
        'consent_mode_mapping',
        'blocking_rules',
        'supports_accept_once',
        'supports_accept_provider',
        'ui_config',
        'compliance',
        'test_manifest',
    ];

    public $translatable = ['name', 'purpose'];

    protected $casts = [
        'cookie_names' => 'array',
        'is_active' => 'boolean',
        // Consent Execution Registry v2
        'service_domains' => 'array',
        'consent_mode_mapping' => 'array',
        'blocking_rules' => 'array',
        'supports_accept_once' => 'boolean',
        'supports_accept_provider' => 'boolean',
        'ui_config' => 'array',
        'compliance' => 'array',
        'test_manifest' => 'array',
    ];

    protected static function booted(): void
    {
        static::deleted(function (Service $service) {
            // Auto-delete the provider if it was library-installed and has no remaining services
            if ($service->provider_id) {
                $provider = Provider::find($service->provider_id);
                if ($provider && $provider->is_library && $provider->services()->count() === 0) {
                    $provider->delete();
                }
            }
        });
    }

    /**
     * Whether this service was installed from the template library.
     */
    public function isFromLibrary(): bool
    {
        return $this->template_key !== null;
    }

    /**
     * Check if a newer library version is available.
     * Returns the new version string, or null if up-to-date / custom.
     */
    public function getAvailableUpdate(): ?string
    {
        if (! $this->isFromLibrary()) {
            return null;
        }

        $templates = \App\Services\TemplateLibraryService::getTemplates();
        $template = $templates[$this->template_key] ?? null;

        if (! $template) {
            return null;
        }

        $libraryVersion = $template['version'] ?? '1.0.0';

        if (version_compare($libraryVersion, $this->template_version ?? '0.0.0', '>')) {
            return $libraryVersion;
        }

        return null;
    }

    /**
     * Update this service to the latest template version.
     */
    public function updateFromTemplate(): bool
    {
        if (! $this->isFromLibrary()) {
            return false;
        }

        $templates = \App\Services\TemplateLibraryService::getTemplates();
        $tpl = $templates[$this->template_key] ?? null;

        if (! $tpl) {
            return false;
        }

        $this->update([
            'template_version' => $tpl['version'] ?? '1.0.0',
            'purpose' => $tpl['purpose'],
            'integration_type' => $tpl['integration_type'] ?? 'browser_tag',
            'provider_key' => $tpl['provider_key'] ?? null,
            'service_domains' => $tpl['domains'] ?? null,
            'consent_mode_mapping' => $tpl['consent_mode_mapping'] ?? null,
            'supports_accept_once' => $tpl['supports_accept_once'] ?? false,
            'supports_accept_provider' => $tpl['supports_accept_provider'] ?? false,
        ]);

        if (isset($tpl['cookies']) && is_array($tpl['cookies'])) {
            $existingCookies = $this->cookies()->pluck('name')->toArray();
            foreach ($tpl['cookies'] as $cookie) {
                if (! in_array($cookie['name'], $existingCookies)) {
                    $this->cookies()->create([
                        'name' => $cookie['name'],
                        'lifetime' => $cookie['lifetime'],
                        'purpose' => $cookie['purpose'],
                        'hostname' => '',
                    ]);
                }
            }
        }

        return true;
    }

    public function cookieGroup(): BelongsTo
    {
        return $this->belongsTo(CookieGroup::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(ServiceSetting::class);
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function cookies(): HasMany
    {
        return $this->hasMany(ServiceCookie::class);
    }
}
