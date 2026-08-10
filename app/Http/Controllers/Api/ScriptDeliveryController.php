<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Runtime\Consumer\ManifestConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class ScriptDeliveryController extends Controller
{
    /**
     * Dynamically serve the bundled manager.js prepended with the Domain's configuration object.
     */
    public function __invoke(Request $request, $site_id)
    {
        // 1. Find the domain (lightweight — no eager loading yet)
        $domain = Domain::where('site_id', $site_id)->where('is_active', true)->first();

        if (!$domain) {
            return response('console.error("[YCookies] Domain not found or inactive.");', 404)
                ->header('Content-Type', 'application/javascript');
        }

        $lang = $this->resolveLanguage($request, $domain);
        app()->setLocale($lang);

        // 2. Try manifest path first (if enabled)
        if ($domain->manifest_enabled) {
            $service = app(ManifestConfigService::class);
            $config = $service->resolveConfig($domain, $lang);

            if ($config) {
                $revisionNumber = $service->getRevisionNumber($domain);
                // Cache key includes revision number; v2-prefix forces bust of any V1 cache entries.
                $cacheKey = "script_delivery_v2_manifest:{$site_id}:{$lang}:{$revisionNumber}";

                $finalJavascript = Cache::remember($cacheKey, 300, function () use ($config) {
                    return $this->assembleScript($config);
                });

                return $this->scriptResponse($finalJavascript);
            }
            // Manifest resolution failed — fall through to legacy
        }

        // 3. Legacy path: eager-load relations and build config from DB
        $domain = Domain::where('site_id', $site_id)->where('is_active', true)->with([
            'cookieBar',
            'cookieGroups' => function ($q) {
                $q->orderBy('sort_order');
            },
            'cookieGroups.services' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            },
            'cookieGroups.services.provider',
            'cookieGroups.services.cookies',
            'cookieGroups.services.settings',
            'scriptBlockers' => function ($q) {
                $q->where('is_active', true);
            },
            'scriptBlockers.service',
            'contentBlockers' => function ($q) {
                $q->where('is_active', true);
            },
            'contentBlockers.service',
            'contentBlockers.provider',
        ])->first();

        if (!$domain) {
            return response('console.error("[YCookies] Domain not found or inactive.");', 404)
                ->header('Content-Type', 'application/javascript');
        }

        $cacheKey = "script_delivery_v2:{$site_id}:{$lang}";
        $finalJavascript = Cache::remember($cacheKey, 300, function () use ($domain, $lang) {
            $config = $this->buildLegacyConfig($domain, $lang);
            return $this->assembleScript($config);
        });

        return $this->scriptResponse($finalJavascript);
    }

    /**
     * Resolve the request language, validated against active languages.
     */
    private function resolveLanguage(Request $request, Domain $domain): string
    {
        $lang = $request->query('lang', config('app.locale'));
        $loc = $domain->localization ?? [];
        if (!($loc['auto_detect'] ?? true)) {
            $lang = $loc['default_language'] ?? 'en';
        }

        $activeLangs = Cache::remember('active_language_codes', 600, function () {
            return \App\Models\Language::where('is_active', true)->pluck('code')->toArray();
        });
        if (!in_array($lang, $activeLangs, true)) {
            $lang = $loc['default_language'] ?? 'en';
        }

        return $lang;
    }

    /**
     * Assemble the final JavaScript string: config injection + compiled manager.js.
     */
    private function assembleScript(array $config): string
    {
        // Ensure we load the production build (Vite generates a manifest)
        $manifestPath = public_path('build/manifest.json');
        $managerFile = 'resources/js/manager.js';
        $scriptContent = '';

        if (File::exists($manifestPath)) {
            $manifest = json_decode(File::get($manifestPath), true);
            if (isset($manifest[$managerFile]['file'])) {
                $compiledPath = public_path('build/' . $manifest[$managerFile]['file']);
                if (File::exists($compiledPath)) {
                    $scriptContent = File::get($compiledPath);
                }
            }
        }

        // Fallback for local dev if Vite isn't building yet
        if (empty($scriptContent)) {
            $rawPath = resource_path('js/manager.js');
            if (File::exists($rawPath)) {
                $scriptContent = File::get($rawPath);
            } else {
                return 'console.error("[YCookies] Manager script not found on server.");';
            }
        }

        $jsonConfig = json_encode($config);

        return <<<JS
window.YCookies = window.YCookies || {};
window.YCookies.config = {$jsonConfig};

// --- YCookies Core Engine (START) ---
{$scriptContent}
// --- YCookies Core Engine (END) ---
JS;
    }

    /**
     * Return a cacheable JavaScript response.
     */
    private function scriptResponse(string $javascript): \Illuminate\Http\Response
    {
        return response($javascript)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300, s-maxage=300')
            ->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Build config from DB relations (legacy path — identical to original logic).
     */
    private function buildLegacyConfig(Domain $domain, string $lang): array
    {
        $domainUiConfig = $this->filterNullValues($domain->ui_config ?? []);

        // Resolve cross-domain siblings if enabled
        $crossDomainsList = [];
        if ($domain->cross_domain_enabled && $domain->group_id) {
            $crossDomainsList = Domain::where('group_id', $domain->group_id)
                ->where('is_active', true)
                ->where('cross_domain_enabled', true)
                ->pluck('name')
                ->toArray();
        }

        return [
            'site_id' => $domain->site_id,
            'name' => $domain->name,
            'url' => $domain->url,
            'ui_config' => array_replace_recursive(
                [
                    'layout' => 'box_modal',
                    'position' => 'center',
                    'trigger_mode' => 'interaction',
                    'colors' => ['primary' => '#3b82f6', 'background' => '#111827', 'text' => '#f3f4f6', 'link' => '#60a5fa'],
                    'typography' => ['font_family' => 'system-ui, -apple-system, sans-serif', 'font_size' => 15],
                    'effects' => ['glassmorphism' => false],
                    'buttons' => ['show_accept_all' => true, 'show_accept_essential' => true, 'show_settings' => true, 'show_save_consent' => false, 'show_accept_essential_only' => false],
                ],
                $domain->cookieBar?->theme_settings ?? [],
                $domain->cookieBar?->ui_config ?? [],
                $domainUiConfig
            ),
            'translations' => collect($domain->cookieBar?->translations ?? [])->map(function ($section) use ($lang) {
                if (is_array($section)) {
                    return collect($section)->map(function ($langValues) use ($lang) {
                        if (is_array($langValues)) {
                            return $langValues[$lang] ?? $langValues['en'] ?? current($langValues) ?: '';
                        }
                        return $langValues;
                    })->toArray();
                }
                return $section;
            })->toArray(),
            'geo_restriction_eu' => $domain->geo_restriction_eu,
            'consent_version' => $domain->consent_version,
            'cross_domain_enabled' => $domain->cross_domain_enabled,
            'cross_domains_list' => $crossDomainsList,
            'localization' => [
                'locale' => $lang,
                'auto_detect' => $domain->localization['auto_detect'] ?? true,
                'default_language' => $domain->localization['default_language'] ?? 'en',
                'show_switcher' => $domain->localization['show_switcher'] ?? true,
                'current_is_rtl' => \App\Models\Language::where('code', $lang)->value('is_rtl') ?? false,
            ],
            'languages' => \App\Models\Language::where('is_active', true)->select('code', 'name', 'is_rtl')->get()->mapWithKeys(function ($l) {
                return [$l->code => $l];
            })->toArray(),
            'tcm_config' => [
                'enabled' => $domain->tcm_config['enabled'] ?? true,
                'advanced_consent_mode' => $domain->tcm_config['advanced_consent_mode'] ?? false,
                'mapping' => $domain->tcm_config['mapping'] ?? [
                    'marketing' => ['ad_storage', 'ad_user_data', 'ad_personalization', 'personalization_storage'],
                    'statistics' => ['analytics_storage', 'functionality_storage', 'security_storage']
                ],
                'regional_defaults' => $domain->tcm_config['regional_defaults'] ?? [],
                'has_google_services' => $domain->cookieGroups->flatMap->services->contains(function ($service) {
                    if ($service->is_active && $service->consent_mode_mapping) {
                        $signals = $service->consent_mode_mapping['consent_signals'] ?? [];
                        return !empty($signals);
                    }
                    return $service->is_active && (in_array(strtolower($service->provider?->name ?? $service->name), ['google analytics', 'google tag manager', 'google ads']) || in_array($service->key, ['google-analytics', 'google-tag-manager', 'google-ads']));
                }),
            ],
            'visitor_country' => request()->header('CF-IPCountry'),
            'tcf_enabled' => $domain->tcf_config['enabled'] ?? false,
            'tcf_cmp_id' => $domain->tcf_config['cmp_id'] ?? 999,
            'tcf_config' => $domain->tcf_config ?? ['enabled' => false],
            'auto_blocking' => $domain->getAutoBlockingConfig(),
            'cookie_groups' => $domain->cookieGroups->filter(fn ($group) => $group->services->isNotEmpty())->values()->toArray(),
            'script_blockers' => $domain->scriptBlockers
                ->filter(fn ($blocker) => ($blocker->blocker_type ?? 'script') === 'script')
                ->values()
                ->toArray(),
            'style_blockers' => $domain->scriptBlockers
                ->filter(fn ($blocker) => ($blocker->blocker_type ?? 'script') === 'style')
                ->values()
                ->toArray(),
            'content_blockers' => $domain->contentBlockers->toArray(),
        ];
    }

    /**
     * Recursively filter null values from an array to prevent overriding defaults.
     */
    private function filterNullValues(array $array): array
    {
        $filtered = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = $this->filterNullValues($value);
                if (!empty($value)) {
                    $filtered[$key] = $value;
                }
            } elseif ($value !== null && $value !== false) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }
}
