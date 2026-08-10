<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ScriptBlocker;
use App\Runtime\Consumer\RevisionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Proxy Config Controller
 *
 * Serves the host-config contract for the Node.js proxy service.
 * The Node proxy calls GET /api/proxy-config/{host} to get everything
 * it needs to proxy one domain: origin settings, blocker rules,
 * bootstrapper URL, feature flags, and a revision number.
 *
 * Response is signed with HMAC-SHA256 so the Node proxy can verify
 * it came from Laravel and hasn't been tampered with.
 */
class ProxyConfigController extends Controller
{
    /**
     * Return proxy config for a given hostname.
     */
    public function show(Request $request, string $host): JsonResponse
    {
        $host = strtolower(trim($host));

        if (empty($host)) {
            return response()->json(['error' => 'Missing host parameter'], 400);
        }

        $cacheKey = "proxy_config:{$host}";

        $config = Cache::remember($cacheKey, 60, function () use ($host) {
            return $this->buildConfig($host);
        });

        if (! $config) {
            return response()->json(['error' => 'Domain not found or not proxy-enabled'], 404);
        }

        $response = response()->json($config);

        // Sign the response body with HMAC-SHA256
        $secret = config('services.proxy.shared_secret');
        if ($secret) {
            $signature = hash_hmac('sha256', $response->getContent(), $secret);
            $response->header('X-Signature', $signature);
        }

        // Set ETag based on revision for conditional requests
        $response->header('ETag', '"'.$config['revision'].'"');

        // Support If-None-Match from Node proxy
        $ifNoneMatch = request()->header('If-None-Match');
        if ($ifNoneMatch && trim($ifNoneMatch, '"') === (string) $config['revision']) {
            return response()->json(null, 304);
        }

        return $response;
    }

    /**
     * Build the config payload for a proxy-enabled domain.
     */
    public function buildConfig(string $host): ?array
    {
        $domain = Domain::withoutGlobalScopes()
            ->where('name', $host)
            ->where('is_active', true)
            ->where('proxy_enabled', true)
            ->where(function ($q) {
                $q->whereNotNull('origin_url')
                    ->orWhereNotNull('origin_ip')
                    ->orWhereNotNull('origin_subdomain');
            })
            ->with([
                'scriptBlockers' => function ($q) {
                    $q->where('is_active', true)->with('service');
                },
                'contentBlockers' => function ($q) {
                    $q->where('is_active', true)->with('service');
                },
                'fallbackContentBlocker',
            ])
            ->first();

        if (! $domain) {
            return null;
        }

        // Use config_version as the revision (not updated_at).
        // This captures ALL config changes including env-derived values
        // because the observer bumps config_version on every save.
        // We include app.url in the hash so env changes also invalidate.
        $revision = $domain->config_version.':'.crc32(config('app.url'));

        $appUrl = rtrim(config('app.url'), '/');

        return [
            'revision' => $revision,
            'domain' => $domain->name,
            'site_id' => $domain->site_id,

            'origin' => [
                'subdomain' => $domain->origin_subdomain,
                'auth_token' => $domain->origin_auth_token,
                'auth_token_legacy' => $domain->origin_auth_token_legacy,
                'auth_legacy_expires_at' => $domain->origin_auth_legacy_expires_at?->toIso8601String(),
                'url' => $domain->origin_url,
                'ip' => $domain->origin_ip,
                'host' => $domain->origin_host ?: $domain->name,
            ],

            'proxy' => [
                'enabled' => $domain->proxy_enabled,
                'status' => $domain->proxy_status ?? 'active',
                'engine' => 'node',
            ],

            'rate_limit' => [
                'enabled' => $domain->rate_limit_enabled ?? true,
                'max_requests_per_minute' => $domain->rate_limit_max_requests_per_minute ?? 200,
                'exclude_paths' => $domain->rate_limit_exclude_paths ?? [],
            ],

            'consent' => [
                'mode_enabled' => $domain->consent_mode_enabled ?? false,
                'advanced_mode' => $domain->advanced_consent_mode ?? false,
                'version' => $domain->consent_version ?? 1,
            ],

            'auto_blocking' => $domain->getAutoBlockingConfig(),

            'bootstrapper' => [
                // Legacy: dynamic JS bundle (config + manager.js in one response)
                'script_url' => "{$appUrl}/api/script/{$domain->site_id}.js",
                'boot_url' => "{$appUrl}/api/boot/{$domain->site_id}.js",
                // Static loader: immutable Vite-built asset (CDN-cacheable)
                'static_loader_url' => $this->resolveStaticLoaderUrl($appUrl),
                // API base for cross-origin config fetch
                'api_base' => $appUrl,
            ],

            'script_blockers' => $domain->scriptBlockers
                ->filter(fn ($blocker) => ($blocker->blocker_type ?? 'script') === 'script')
                ->values()
                ->map(function ($blocker) {
                    return [
                        'key' => $blocker->key,
                        'handles' => $blocker->handles ?? [],
                        'phrases' => $blocker->phrases ?? [],
                        'on_exist' => $blocker->on_exist,
                        'blocker_type' => $blocker->blocker_type ?? 'script',
                        'service' => $blocker->service ? $blocker->service->key : null,
                        'require_group' => $blocker->require_group,
                    ];
                })->toArray(),

            'style_blockers' => $domain->scriptBlockers
                ->filter(fn ($blocker) => ($blocker->blocker_type ?? 'script') === 'style')
                ->values()
                ->map(function ($blocker) {
                    return [
                        'key' => $blocker->key,
                        'handles' => $blocker->handles ?? [],
                        'phrases' => $blocker->phrases ?? [],
                        'on_exist' => $blocker->on_exist,
                        'blocker_type' => 'style',
                        'service' => $blocker->service ? $blocker->service->key : null,
                        'require_group' => $blocker->require_group,
                    ];
                })->toArray(),

            'universal_script_blocker' => $this->buildUniversalBlocker($domain, ScriptBlocker::TYPE_SCRIPT),
            'universal_style_blocker' => $this->buildUniversalBlocker($domain, ScriptBlocker::TYPE_STYLE),

            'content_blockers' => $domain->contentBlockers->map(function ($blocker) {
                return [
                    'key' => $blocker->key,
                    'name' => $blocker->name,
                    'hosts' => $blocker->hosts ?? [],
                    'service' => $blocker->service ? $blocker->service->key : null,
                    'display_mode' => $blocker->display_mode ?? 'inline',
                    'floating_position' => $blocker->floating_position,
                    'floating_icon_url' => $blocker->floating_icon_url,
                    'floating_label' => $blocker->floating_label,
                    'html_code' => $blocker->html_code,
                    'css_code' => $blocker->css_code,
                ];
            })->values()->toArray(),

            'fallback_content_blocker' => $domain->fallbackContentBlocker ? [
                'key' => $domain->fallbackContentBlocker->key,
                'name' => $domain->fallbackContentBlocker->name,
                'hosts' => ['*'],
                'service' => $domain->fallbackContentBlocker->service?->key,
                'html_code' => $domain->fallbackContentBlocker->html_code,
                'css_code' => $domain->fallbackContentBlocker->css_code,
            ] : null,

            'cookie_policy' => $this->buildCookiePolicy($domain),

            'features' => [
                'lna_shield' => true, // Always enabled for proxy domains
                'geo_restriction_eu' => $domain->geo_restriction_eu ?? false,
            ],

            // Runtime manifest: included when manifest_enabled is true
            // The Node proxy uses this to switch from legacy DB-composed config
            // to immutable manifest-derived config per domain.
            'manifest' => $domain->manifest_enabled
                ? $this->buildManifestBlock($domain)
                : null,
        ];
    }

    /**
     * Fetch the tenant-level universal fallback blocker for a given type.
     */
    protected function buildUniversalBlocker(Domain $domain, string $blockerType): ?array
    {
        if (! $domain->group_id) {
            return null;
        }

        $blocker = ScriptBlocker::withoutGlobalScopes()
            ->where('group_id', $domain->group_id)
            ->where('is_system', true)
            ->where('is_active', true)
            ->where('blocker_type', $blockerType)
            ->first();

        if (! $blocker) {
            return null;
        }

        return [
            'key' => $blocker->key,
            'require_group' => $blocker->require_group ?? 'uncategorized',
            'hosts' => $blocker->hosts ?? ['*'],
        ];
    }

    /**
     * Build cookie filtering policy from the domain's CookieGroups.
     *
     * Essential cookies come from groups where is_required=true.
     * If no cookie groups are configured, falls back to passthrough mode
     * (all cookies allowed — safe default to avoid breaking sites).
     */
    public function buildCookiePolicy(Domain $domain): array
    {
        $cookieGroups = $domain->cookieGroups()
            ->with(['services.cookies'])
            ->get();

        if ($cookieGroups->isEmpty()) {
            return ['mode' => 'passthrough'];
        }

        // Collect cookie names from essential (is_required) groups
        $essentialPatterns = $cookieGroups
            ->where('is_required', true)
            ->flatMap(function ($group) {
                return $group->services->flatMap(function ($service) {
                    return $service->cookies->pluck('name');
                });
            })
            ->unique()
            ->values()
            ->toArray();

        return [
            'mode' => 'allowlist',
            'essential_patterns' => $essentialPatterns,
            // NOTE: __Secure-/__Host- prefixes are NOT auto-included.
            // Security-prefixed does not mean consent-exempt.
            // Admins must add these explicitly if needed for their site.
            'essential_prefixes' => [],
        ];
    }

    /**
     * Resolve the static loader URL from the Vite manifest.
     *
     * Returns the immutable, hashed URL for the compiled manager.js asset.
     * This file is CDN-cacheable and version-stable per deploy.
     * Falls back to null if the manifest doesn't exist (dev mode).
     */
    public function resolveStaticLoaderUrl(string $appUrl): ?string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached ?: null;
        }

        $manifestPath = public_path('build/manifest.json');
        if (! file_exists($manifestPath)) {
            $cached = '';

            return null;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $managerEntry = 'resources/js/manager.js';

        if (isset($manifest[$managerEntry]['file'])) {
            $file = $manifest[$managerEntry]['file'];
            $assetPath = public_path('build/'.$file);
            if (is_file($assetPath)) {
                $cached = "{$appUrl}/build/{$file}";

                return $cached;
            }
        }

        $cached = '';

        return null;
    }

    /**
     * Build manifest block for the proxy config response.
     *
     * Returns the active revision's base artifact and metadata so the
     * Node proxy can use immutable manifest-derived config instead of
     * the legacy DB-composed fields.
     *
     * Fail-safe: returns { enabled: false } if no published revision exists.
     */
    protected function buildManifestBlock(Domain $domain): array
    {
        try {
            $resolver = app(RevisionResolver::class);
            $resolved = $resolver->resolveActive($domain->name);

            if (! $resolved) {
                Log::info('ManifestBlock: no active revision for '.$domain->name);

                return ['enabled' => false, 'reason' => 'no_active_revision'];
            }

            return [
                'enabled' => true,
                'revision_number' => $resolved->revisionNumber,
                'schema_version' => $resolved->schemaVersion,
                'manifest_hash' => $resolved->manifestHash,
                'signature' => $resolved->manifestSignature,
                'base_artifact' => $resolved->baseArtifact,
                'published_at' => $resolved->publishedAt,
            ];
        } catch (\Throwable $e) {
            Log::error('ManifestBlock: failed to resolve revision', [
                'domain' => $domain->name,
                'error' => $e->getMessage(),
            ]);

            return ['enabled' => false, 'reason' => 'resolver_error'];
        }
    }
}
