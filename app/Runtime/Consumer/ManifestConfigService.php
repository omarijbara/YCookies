<?php

declare(strict_types=1);

namespace App\Runtime\Consumer;

use App\Models\Domain;
use App\Models\Language;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ManifestConfigService — Shared projection logic for manifest consumers.
 *
 * Provides a single source of truth for resolving config data from
 * published manifest revisions. Used by:
 *   - ManifestProjectionController (/api/config/)
 *   - ScriptDeliveryController (/api/script/)
 *   - BootstrapperController (/api/boot/)
 *
 * All methods return null when manifest mode is not active or
 * resolution fails — callers must fall back to legacy DB queries.
 */
class ManifestConfigService
{
    private RevisionResolver $resolver;

    public function __construct(RevisionResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Resolve the full config array from the manifest, projected into
     * the legacy /api/config/ JSON shape for a specific language.
     *
     * Returns null if manifest mode is inactive or no revision exists.
     */
    public function resolveConfig(Domain $domain, string $lang): ?array
    {
        if (!$domain->manifest_enabled) {
            return null;
        }

        $resolved = $this->resolver->resolveActive($domain->name);
        if (!$resolved) {
            Log::info("ManifestConfigService: No revision for {$domain->name}, skipping manifest path");
            return null;
        }

        $cacheKey = "manifest_config:{$domain->site_id}:{$lang}:{$resolved->revisionNumber}";

        return Cache::remember($cacheKey, 300, function () use ($resolved, $domain, $lang) {
            return $this->projectFromManifest($resolved->baseArtifact, $domain, $lang, $resolved->revisionNumber);
        });
    }

    /**
     * Resolve the script_blockers array from the manifest base artifact.
     *
     * Returns null if manifest mode is inactive or no revision exists.
     * Returns an empty array if there are no script blockers.
     */
    public function resolveBlocklist(Domain $domain): ?array
    {
        if (!$domain->manifest_enabled) {
            return null;
        }

        $resolved = $this->resolver->resolveActive($domain->name);
        if (!$resolved) {
            return null;
        }

        $baseArtifact = $resolved->baseArtifact;
        $scriptBlockers = $baseArtifact['script_blockers'] ?? [];

        // Transform manifest script_blockers into the bootstrapper blocklist format.
        // The bootstrapper expects: [{ pattern, type, service_key }]
        // The manifest stores: [{ key, name, handles, phrases, on_exist, service }]
        $blocklist = [];
        foreach ($scriptBlockers as $blocker) {
            $handles = $blocker['handles'] ?? [];
            $phrases = $blocker['phrases'] ?? [];
            $serviceKey = $blocker['service'] ?? $blocker['key'] ?? null;

            foreach ($handles as $handle) {
                if (!empty($handle)) {
                    $blocklist[] = [
                        'pattern' => $handle,
                        'type' => 'handle',
                        'service_key' => $serviceKey,
                    ];
                }
            }

            foreach ($phrases as $phrase) {
                if (!empty($phrase)) {
                    $blocklist[] = [
                        'pattern' => $phrase,
                        'type' => 'phrase',
                        'service_key' => $serviceKey,
                    ];
                }
            }
        }

        return $blocklist;
    }

    /**
     * Get the raw base artifact from the active revision.
     *
     * Returns null if manifest mode is inactive or no revision exists.
     */
    public function resolveBaseArtifact(Domain $domain): ?array
    {
        if (!$domain->manifest_enabled) {
            return null;
        }

        $resolved = $this->resolver->resolveActive($domain->name);
        return $resolved?->baseArtifact;
    }

    /**
     * Get the active revision number for a domain.
     * Returns null if no active manifest revision.
     */
    public function getRevisionNumber(Domain $domain): ?int
    {
        if (!$domain->manifest_enabled) {
            return null;
        }

        $resolved = $this->resolver->resolveActive($domain->name);
        return $resolved?->revisionNumber;
    }

    /**
     * Project the manifest base artifact into the legacy config shape.
     *
     * This is the authoritative projection logic — used by all consumers.
     */
    protected function projectFromManifest(array $base, Domain $domain, string $lang, int $revisionNumber): array
    {
        $translations = $this->resolveTranslations($base['translations'] ?? [], $lang);

        $currentIsRtl = Language::where('code', $lang)->value('is_rtl') ?? false;
        $localization = $base['localization'] ?? [];
        if (is_array($localization)) {
            $localization['current_is_rtl'] = $currentIsRtl;
            $localization['locale'] = $lang;
        }

        return [
            'version'              => '1.0.0',
            'site_id'              => $base['site_id'] ?? $domain->site_id,
            'domain'               => $base['domain'] ?? $domain->name,
            'cross_domain_enabled' => $base['cross_domain_enabled'] ?? false,
            'cross_domains_list'   => $base['cross_domains_list'] ?? [],
            'theme'                => $base['ui_config']['theme'] ?? [],
            'translations'         => $translations,
            'ui_config'            => $base['ui_config'] ?? [],
            'localization'         => $localization,
            'languages'            => $base['languages'] ?? [],
            'tcm_config'           => $base['tcm_config'] ?? [],
            'tcf_config'           => $base['tcf_config'] ?? ['enabled' => false],
            'geo_rules'            => $base['geo_rules'] ?? [],
            'geo_restriction_eu'   => $base['geo_restriction_eu'] ?? false,
            'consent_version'      => $base['consent_version'] ?? 1,

            // Request-time derived: NOT in manifest (varies per-request)
            'visitor_country'      => request()->header('CF-IPCountry'),

            'cookie_groups'        => $base['cookie_groups'] ?? [],
            'content_blockers'     => $base['content_blockers'] ?? [],
            'script_blockers'      => $base['script_blockers'] ?? [],
            'style_blockers'       => $base['style_blockers'] ?? [],
            'auto_blocking'        => $base['auto_blocking'] ?? [
                'content' => true,
                'script' => true,
                'style' => true,
                'service' => true,
            ],

            'callbacks'            => $base['callbacks'] ?? [
                'onReady'         => 'window.ycookiesDispatchEvent("ready")',
                'onConsentUpdate' => 'window.ycookiesDispatchEvent("consent_update")',
            ],

            // Manifest metadata — additive, doesn't break SDK
            '_manifest_revision'   => $revisionNumber,
        ];
    }

    /**
     * Resolve multi-language translations to a single language.
     *
     * Manifest stores: { "banner": { "title": { "en": "...", "de": "..." } } }
     * Legacy returns:   { "banner": { "title": "..." } }
     */
    protected function resolveTranslations(array $translations, string $lang): array
    {
        return collect($translations)->map(function ($section) use ($lang) {
            if (is_array($section)) {
                return collect($section)->map(function ($langValues) use ($lang) {
                    if (is_array($langValues)) {
                        return $langValues[$lang] ?? $langValues['en'] ?? current($langValues) ?: '';
                    }
                    return $langValues;
                })->toArray();
            }
            return $section;
        })->toArray();
    }
}
