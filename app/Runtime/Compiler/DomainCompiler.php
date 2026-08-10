<?php

declare(strict_types=1);

namespace App\Runtime\Compiler;

use App\Models\Domain;
use App\Runtime\Schema\ManifestSchema;
use Illuminate\Support\Facades\Log;

/**
 * DomainCompiler — Compiles a domain's authoring state into immutable runtime artifacts.
 *
 * This is the "kitchen" that takes all the raw ingredients (Domain, CookieBar, CookieGroups,
 * Services, Blockers, etc.) and produces the canonical base artifact, route index, and overlays.
 *
 * The compiler does NOT persist anything — it returns a CompileResult that the
 * RevisionPublisher uses to atomically create a new revision.
 *
 * Key invariants:
 *   - Output is deterministic: same inputs → same outputs → same hashes
 *   - Forbidden fields (visitor_country, etc.) are never included
 *   - Request-derived context is never baked into artifacts
 */
class DomainCompiler
{
    /**
     * Compile a domain's current state into runtime artifacts.
     *
     * @param  Domain  $domain  The domain to compile (should be loaded with eager relations)
     */
    public function compile(Domain $domain): CompileResult
    {
        // ── 1. Collect all authoring inputs ────────────────────────
        $domain->loadMissing([
            'cookieBar',
            'cookieGroups.services.cookies',
            'cookieGroups.services.provider',
            'scriptBlockers.service',
            'contentBlockers.service',
            'services',
        ]);

        // ── 2. Build the base artifact ─────────────────────────────
        $baseArtifact = $this->buildBaseArtifact($domain);

        // Validate: no forbidden fields
        $violations = ManifestSchema::validateNoForbiddenFields($baseArtifact);
        if (! empty($violations)) {
            Log::error('Compiler: forbidden fields in base artifact', [
                'domain' => $domain->name,
                'violations' => $violations,
            ]);
            // Remove them rather than failing — defensive compilation
            $baseArtifact = $this->stripForbiddenFields($baseArtifact);
        }

        $baseJson = ManifestSchema::canonicalize($baseArtifact);
        $baseHash = hash('sha256', $baseJson);

        // ── 3. Build route index and overlays ──────────────────────
        // Phase 1: no overlays — base-only. Overlay support added later
        // when the admin UI for route-specific configs is built.
        $routeIndex = null;
        $routeIndexJson = '';
        $routeIndexHash = '';
        $overlays = [];

        // ── 4. Build the manifest envelope ─────────────────────────
        $artifactRefs = [
            'base' => ManifestSchema::buildArtifactRef($baseHash, strlen($baseJson)),
        ];
        if ($routeIndexJson !== '') {
            $artifactRefs['route_index'] = ManifestSchema::buildArtifactRef($routeIndexHash, strlen($routeIndexJson));
        }
        if (! empty($overlays)) {
            $artifactRefs['overlays'] = array_map(fn ($o) => [
                'id' => $o['overlay_id'],
                'hash' => $o['overlay_hash'],
                'size' => strlen($o['overlay_json']),
                'algorithm' => 'sha256',
            ], $overlays);
        }

        $manifest = ManifestSchema::buildManifestEnvelope([
            'domain' => $domain->name,
            'site_id' => $domain->site_id,
            'revision' => 0, // placeholder — publisher assigns the real number
            'issued_at' => now()->toIso8601String(),
            'artifacts' => $artifactRefs,
        ]);

        $manifestJson = ManifestSchema::canonicalize($manifest);
        $manifestHash = hash('sha256', $manifestJson);

        // ── 5. Compute compile inputs hash ─────────────────────────
        // This hash captures the state of all source data. If it hasn't
        // changed since the last compile, we can skip re-publishing.
        $compileInputsHash = $this->computeInputsHash($domain);

        return new CompileResult(
            manifest: $manifest,
            baseArtifact: $baseArtifact,
            baseArtifactJson: $baseJson,
            baseArtifactHash: $baseHash,
            routeIndex: $routeIndex,
            routeIndexJson: $routeIndexJson,
            routeIndexHash: $routeIndexHash,
            overlays: $overlays,
            manifestJson: $manifestJson,
            manifestHash: $manifestHash,
            compileInputsHash: $compileInputsHash,
        );
    }

    /**
     * Build the canonical base artifact from a domain's authoring state.
     */
    protected function buildBaseArtifact(Domain $domain): array
    {
        $cookieBar = $domain->cookieBar;

        // Build cookie groups with services
        $cookieGroups = $domain->cookieGroups->map(function ($group) {
            return [
                'key' => $group->key,
                'name' => $group->name,
                'description' => $group->description ?? '',
                'is_required' => (bool) $group->is_required,
                'is_preselected' => (bool) $group->is_preselected,
                'services' => $group->services->map(function ($service) {
                    return [
                        'key' => $service->key,
                        'name' => $service->name,
                        'provider' => $service->provider?->name,
                        'provider_details' => $this->buildProviderDetails($service->provider),
                        'purpose' => $service->purpose ?? '',
                        'integration_type' => $service->integration_type ?? 'browser_tag',
                        'provider_key' => $service->provider?->key,
                        'consent_mode_mapping' => $service->consent_mode_mapping,
                        'cookies' => $service->cookies->map(fn ($c) => [
                            'name' => $c->name,
                            'hostname' => $c->hostname,
                            'lifetime' => $c->lifetime,
                            'purpose' => $c->purpose,
                        ])->all(),
                        'payloads' => $service->payloads,
                        'integrations' => $service->integrations,
                    ];
                })->all(),
            ];
        })->filter(fn ($group) => ! empty($group['services']))->values()->all();

        // Build script/style blockers from shared storage
        $scriptBlockers = $domain->scriptBlockers
            ->filter(fn ($blocker) => ($blocker->blocker_type ?? 'script') === 'script' && $blocker->is_active)
            ->values()
            ->map(function ($blocker) {
                return [
                    'key' => $blocker->key,
                    'name' => $blocker->name,
                    'handles' => $blocker->handles ?? [],
                    'phrases' => $blocker->phrases ?? [],
                    'on_exist' => $blocker->on_exist ?? 'change_type',
                    'blocker_type' => $blocker->blocker_type ?? 'script',
                    'service' => $blocker->service?->key,
                ];
            })->all();

        $styleBlockers = $domain->scriptBlockers
            ->filter(fn ($blocker) => ($blocker->blocker_type ?? 'script') === 'style' && $blocker->is_active)
            ->values()
            ->map(function ($blocker) {
                return [
                    'key' => $blocker->key,
                    'name' => $blocker->name,
                    'handles' => $blocker->handles ?? [],
                    'phrases' => $blocker->phrases ?? [],
                    'on_exist' => $blocker->on_exist ?? 'change_type',
                    'blocker_type' => 'style',
                    'service' => $blocker->service?->key,
                ];
            })->all();

        // Build content blockers
        $contentBlockers = $domain->contentBlockers
            ->filter(fn ($blocker) => $blocker->is_active)
            ->values()
            ->map(function ($blocker) {
                return [
                    'key' => $blocker->key,
                    'name' => $blocker->name,
                    'hosts' => $blocker->hosts ?? [],
                    'service' => $blocker->service?->key,
                    'preview_image' => $blocker->preview_image_url,
                    'html_code' => $blocker->html_code,
                    'css_code' => $blocker->css_code,
                    'js_code' => $blocker->js_code,
                    'text_placeholders' => $blocker->text_placeholders ?? [],
                ];
        })->all();

        // Build TCM config
        $tcmConfig = $domain->tcm_config ?? ['enabled' => false];

        // Check for Google services across all cookie groups
        $hasGoogleServices = false;
        foreach ($domain->cookieGroups as $group) {
            foreach ($group->services as $service) {
                if (! empty($service->consent_mode_mapping['consent_signals'])) {
                    $hasGoogleServices = true;
                    break 2;
                }
            }
        }
        if (is_array($tcmConfig)) {
            $tcmConfig['has_google_services'] = $hasGoogleServices;
        }

        // Build cookie policy from script blockers
        $cookiePolicy = ['mode' => 'passthrough'];
        if (! empty($scriptBlockers)) {
            $essentialPatterns = [];
            foreach ($domain->cookieGroups as $group) {
                if ($group->is_required) {
                    foreach ($group->services as $service) {
                        foreach ($service->cookies as $cookie) {
                            $essentialPatterns[] = $cookie->name;
                        }
                    }
                }
            }
            $cookiePolicy = [
                'mode' => count($essentialPatterns) > 0 ? 'allowlist' : 'passthrough',
                'essential_patterns' => $essentialPatterns,
                'essential_prefixes' => [],
            ];
        }

        // Build origin config for proxy
        $origin = null;
        if ($domain->proxy_enabled) {
            $origin = [
                'subdomain' => $domain->origin_subdomain,
                'auth_token' => $domain->origin_auth_token,
                'auth_token_legacy' => $domain->origin_auth_token_legacy,
                'auth_legacy_expires_at' => $domain->origin_auth_legacy_expires_at?->toIso8601String(),
                'url' => $domain->origin_url,
                'ip' => $domain->origin_ip,
                'host' => $domain->origin_host ?? $domain->name,
            ];
        }

        // Bootstrapper config
        $apiBase = rtrim(config('app.url'), '/');
        $siteId = $domain->site_id;

        // Resolve static loader URL from Vite manifest
        $staticLoaderUrl = null;
        $manifestPath = public_path('build/manifest.json');
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (isset($manifest['resources/js/manager.js']['file'])) {
                $file = $manifest['resources/js/manager.js']['file'];
                if (is_file(public_path('build/'.$file))) {
                    $staticLoaderUrl = "{$apiBase}/build/".$file;
                }
            }
        }

        $bootstrapper = [
            'script_url' => "{$apiBase}/api/script/{$siteId}.js",
            'boot_url' => "{$apiBase}/api/boot/{$siteId}.js",
            'static_loader_url' => $staticLoaderUrl,
            'api_base' => $apiBase,
        ];

        // Build the canonical base artifact
        return ManifestSchema::buildBaseArtifact([
            'site_id' => $siteId,
            'domain' => $domain->name,
            'consent_version' => $domain->consent_version ?? 1,
            'cross_domain_enabled' => (bool) ($domain->cross_domain_enabled ?? false),
            'cross_domains_list' => $domain->cross_domains_list ?? [],
            'ui_config' => $this->buildUiConfig($domain, $cookieBar),
            'translations' => $this->buildTranslations($domain, $cookieBar),
            'localization' => $domain->localization ?? [],
            'languages' => $this->buildLanguages($domain),
            'cookie_groups' => $cookieGroups,
            'script_blockers' => $scriptBlockers,
            'content_blockers' => $contentBlockers,
            'style_blockers' => $styleBlockers,
            'auto_blocking' => $domain->getAutoBlockingConfig(),
            'cookie_policy' => $cookiePolicy,
            'tcm_config' => $tcmConfig,
            'tcf_config' => $domain->tcf_config ?? ['enabled' => false],
            'geo_rules' => $domain->geo_rules ?? [],
            'geo_restriction_eu' => (bool) ($domain->geo_restriction_eu ?? false),
            'geo_skipped_countries' => $domain->geo_skipped_countries ?? [],
            'origin' => $origin,
            'proxy' => $domain->proxy_enabled ? [
                'enabled' => true,
                'status' => $domain->proxy_status ?? 'inactive',
                'engine' => 'node',
            ] : null,
            'bootstrapper' => $bootstrapper,
            'features' => [
                'lna_shield' => true,
                'geo_restriction_eu' => (bool) ($domain->geo_restriction_eu ?? false),
            ],
        ]);
    }

    /**
     * Build UI config, merging domain overrides with cookie bar defaults.
     */
    protected function buildUiConfig(Domain $domain, $cookieBar): array
    {
        $uiConfig = $domain->ui_config ?? [];
        $theme = [];

        // Merge cookie bar theming as defaults
        if ($cookieBar) {
            $theme = $cookieBar->theme_settings ?? [];
            $uiConfig = array_merge([
                'layout' => $theme['layout'] ?? 'box_modal',
                'position' => $theme['position'] ?? 'center',
                'colors' => $theme['colors'] ?? [],
                'typography' => $theme['typography'] ?? [],
                'effects' => $theme['effects'] ?? [],
                'buttons' => $theme['buttons'] ?? [],
            ], $uiConfig);
        }

        // The consumer (ManifestConfigService) expects 'theme' to be nested inside 'ui_config'
        $uiConfig['theme'] = $theme;

        // Ensure trigger_mode has a default
        if (! isset($uiConfig['trigger_mode'])) {
            $uiConfig['trigger_mode'] = 'interaction';
        }

        return $uiConfig;
    }

    /**
     * Build translations, falling back to cookie bar defaults if domain overrides are missing.
     */
    protected function buildTranslations(Domain $domain, $cookieBar): array
    {
        $translations = $domain->translations ?? [];

        // If domain has no custom translations, use the cookie bar's translations.
        // The CookieBar model accessor handles merging with global defaults.
        if (empty($translations) && $cookieBar) {
            return $cookieBar->translations;
        }

        return $translations;
    }

    /**
     * Build language/locale data for the domain.
     */
    protected function buildLanguages(Domain $domain): array
    {
        $localization = $domain->localization ?? [];

        // TODO: Expand with actual language table data
        return $localization['languages'] ?? [];
    }

    /**
     * Build provider details array from a Provider model.
     */
    protected function buildProviderDetails($provider): ?array
    {
        if (! $provider) {
            return null;
        }

        return [
            'address' => $provider->address,
            'privacy_policy_url' => $provider->privacy_policy_url,
            'cookie_policy_url' => $provider->cookie_policy_url,
            'opt_out_url' => $provider->opt_out_url,
        ];
    }

    /**
     * Compute a hash of all compilation inputs for change detection.
     *
     * If this hash matches a previous compile, the domain's config
     * hasn't actually changed and we can skip re-publishing.
     */
    protected function computeInputsHash(Domain $domain): string
    {
        $inputs = [
            'domain' => $domain->only([
                'name', 'site_id', 'ui_config', 'localization', 'translations',
                'tcm_config', 'geo_rules', 'tcf_config', 'geo_restriction_eu',
                'geo_skipped_countries',
                'consent_version', 'proxy_enabled', 'origin_url', 'origin_ip',
                'origin_host', 'origin_subdomain', 'origin_auth_token', 'auto_blocking',
            ]),
            'cookie_bar' => $domain->cookieBar?->toArray(),
            'groups' => $domain->cookieGroups->map(fn ($g) => $g->toArray())->all(),
            'services' => $domain->cookieGroups->flatMap(fn ($g) => $g->services)->map(fn ($s) => $s->toArray())->all(),
            'script_blockers' => $domain->scriptBlockers->map(fn ($b) => $b->toArray())->all(),
            'content_blockers' => $domain->contentBlockers->map(fn ($b) => $b->toArray())->all(),
        ];

        return hash('sha256', ManifestSchema::canonicalize($inputs));
    }

    /**
     * Strip forbidden fields from an artifact (defensive compilation).
     */
    protected function stripForbiddenFields(array $artifact): array
    {
        foreach (ManifestSchema::FORBIDDEN_IN_ARTIFACTS as $field) {
            unset($artifact[$field]);
        }

        return $artifact;
    }
}
