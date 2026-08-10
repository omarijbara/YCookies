<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscoveredResource;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ConsentConfigController extends Controller
{
    /**
     * Handle the incoming API request from the YCookies Client script.
     * Generates a fully cached JSON representation of the Tenant's configuration.
     */
    public function __invoke(Request $request, $siteId): \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
    {
        if (!$siteId) {
            return response()->json(['error' => 'Missing site_id parameter'], 400);
        }

        $domain = Domain::where('site_id', $siteId)
            ->where('is_active', true)
            ->with([
                'cookieBar',
                'cookieGroups' => function ($q) {
                    $q->orderBy('sort_order');
                },
                'cookieGroups.services' => function ($q) {
                    $q->where('is_active', true)->orderBy('sort_order');
                },
                'cookieGroups.services.settings',
                'cookieGroups.services.provider',
                'cookieGroups.services.cookies',
                'contentBlockers' => function ($q) {
                    $q->where('is_active', true)->with('service');
                },
                'scriptBlockers' => function ($q) {
                    $q->where('is_active', true)->with('service');
                }
            ])
            ->first();

        if (!$domain) {
            return response()->json(['error' => 'Invalid or inactive site_id'], 404);
        }

        $lang = $request->query('lang', config('app.locale'));
        $loc = $domain->localization ?? [];
        if (!($loc['auto_detect'] ?? true)) {
            $lang = $loc['default_language'] ?? 'en';
        }

        // Validate lang against active languages to prevent cache-busting with arbitrary values
        $activeLangs = \Illuminate\Support\Facades\Cache::remember('active_language_codes', 600, function () {
            return \App\Models\Language::where('is_active', true)->pluck('code')->toArray();
        });
        if (!in_array($lang, $activeLangs, true)) {
            $lang = $loc['default_language'] ?? 'en';
        }

        app()->setLocale($lang);

        // Fetch the domain from Cache (5-minute TTL)
        $cacheKey = "consent_config:{$siteId}:{$lang}";

        $configArray = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($domain, $lang) {

            $filterEmpty = function ($array) use (&$filterEmpty) {
                if (!is_array($array)) return $array;
                foreach ($array as $key => &$value) {
                    if (is_array($value)) {
                        $value = $filterEmpty($value);
                    }
                    if ($value === null || $value === '') {
                        unset($array[$key]);
                    }
                }
                return $array;
            };

            // Format the database records into a clean JSON structure for the JS Client
            return [
                'version' => '1.0.0', // Used later for cache-busting
                'site_id' => $domain->site_id,
                'domain' => $domain->name,
                'cross_domain_enabled' => $domain->cross_domain_enabled ?? false,
                'theme' => $domain->cookieBar?->theme_settings ?? [],
                'translations' => collect($domain->cookieBar?->translations ?? [])->map(function ($section) use ($lang) {
                    if (is_array($section)) {
                        return collect($section)->map(function ($langValues) use ($lang) {
                            if (is_array($langValues)) {
                                return $langValues[$lang] ?? current($langValues) ?: '';
                            }
                            return $langValues;
                        })->toArray();
                    }
                    return $section;
                })->toArray(),
                'ui_config' => array_replace_recursive(
                    [
                        'layout' => 'box_modal',
                        'position' => 'center',
                        'trigger_mode' => 'load',
                        'colors' => [
                            'primary' => '#3b82f6',
                            'background' => '#111827',
                            'text' => '#f3f4f6',
                            'link' => '#60a5fa',
                        ],
                        'typography' => [
                            'font_family' => 'system-ui, -apple-system, sans-serif',
                            'font_size' => 15,
                        ],
                        'effects' => ['glassmorphism' => true],
                        'buttons' => [
                            'show_accept_all' => true,
                            'show_accept_essential' => true,
                            'show_settings' => true,
                            'show_save_consent' => false,
                            'show_accept_essential_only' => false,
                        ]
                    ],
                    $filterEmpty($domain->cookieBar?->theme_settings ?? []),
                    $filterEmpty($domain->cookieBar?->ui_config ?? []),
                    $filterEmpty($domain->ui_config ?? [])
                ),
                'localization' => [
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
                        // v2: detect via consent_mode_mapping (data-driven) OR legacy name matching
                        if ($service->is_active && $service->consent_mode_mapping) {
                            $signals = $service->consent_mode_mapping['consent_signals'] ?? [];
                            return !empty($signals);
                        }
                        return $service->is_active && (in_array(strtolower($service->provider?->name ?? $service->name), ['google analytics', 'google tag manager', 'google ads']) || in_array($service->key, ['google-analytics', 'google-tag-manager', 'google-ads']));
                    }),
                ],
                'tcf_enabled' => $domain->tcf_config['enabled'] ?? false,
                'tcf_cmp_id' => $domain->tcf_config['cmp_id'] ?? 999,
                'tcf_config' => $domain->tcf_config ?? ['enabled' => false],
                'geo_rules' => $domain->geo_rules ?? ['EU' => ['mode' => 'optin', 'groups' => ['essential']]],
                
                // Enterprise Feature Flags
                'geo_restriction_eu' => $domain->geo_restriction_eu,
                'consent_version' => $domain->consent_version,
                'visitor_country' => request()->header('CF-IPCountry'),
                'auto_blocking' => $domain->getAutoBlockingConfig(),

                // Load discovered resources for the Uncategorized group
                'cookie_groups' => $domain->cookieGroups->map(function ($group) use ($domain) {
                    $virtualServices = [];
                    if ($group->key === 'uncategorized') {
                        $virtualServices = DiscoveredResource::withoutGlobalScopes()
                            ->where('domain_id', $domain->id)
                            ->where('status', 'pending')
                            ->get()
                            ->groupBy('provider_host')
                            ->map(function ($resources, $host) {
                                $types = $resources->pluck('resource_type')->unique()->values()->all();
                                $typeSummary = implode(', ', array_map(fn ($t) => $resources->where('resource_type', $t)->count() . ' ' . $t, $types));
                                return [
                                    'key' => 'disc-' . str_replace('.', '-', $host),
                                    'name' => $host,
                                    'purpose' => $typeSummary,
                                    'is_virtual' => true,
                                    'resource_types' => $types,
                                ];
                            })->values()->all();
                    }

                    return [
                        'key' => $group->key,
                        'name' => $group->name,
                        'description' => $group->description,
                        'is_required' => $group->is_required,
                        'is_preselected' => $group->is_preselected,
                        'virtual_services' => $virtualServices,
                        'services' => $group->services->map(function ($service) use ($domain) {
                            
                            $search = [
                                '{{gtm_id}}', '{{ga_id}}', '{{pixel_id}}',
                                '{{ google-tag-manager-id }}',
                                '{{ google-tag-manager-use-own-integration }}',
                                '{{ google-tag-manager-cache-locally }}',
                                '{{ google-tag-manager-cm-active }}',
                                '{{ google-tag-manager-cm-regional-defaults }}',
                                '{{ google-tag-manager-cm-analytics-storage-service-group }}',
                                '{{ google-tag-manager-cm-functionality-storage-service-group }}',
                                '{{ google-tag-manager-cm-personalization-storage-service-group }}',
                                '{{ google-tag-manager-cm-security-storage-service-group }}',
                                '{{ google-tag-manager-cm-ad-storage-service-group }}',
                                '{{ google-tag-manager-cm-ad-user-data-service-group }}',
                                '{{ google-tag-manager-cm-ad-personalization-service-group }}',
                                '{{ upload-dir }}',
                                '{{ gtm-local-cache-version }}'
                            ];
                            
                            $replace = [
                                $service->settings->gtm_id ?? '',
                                $service->settings->ga_id ?? '',
                                $service->settings->pixel_id ?? '',
                                $service->settings->gtm_id ?? '',
                                '0', // google-tag-manager-use-own-integration
                                ($service->settings->gtm_cache_locally ?? false) ? '1' : '0', // google-tag-manager-cache-locally
                                '0', // google-tag-manager-cm-active
                                '{}', // google-tag-manager-cm-regional-defaults
                                'statistics', 
                                'statistics',
                                'marketing',
                                'statistics',
                                'marketing',
                                'marketing',
                                'marketing',
                                url('storage/ycookies/gtm'), // upload-dir
                                ($service->settings && $service->settings->updated_at) ? $service->settings->updated_at->timestamp : time() // gtm-local-cache-version
                            ];

                            return [
                                'key' => $service->key,
                                'name' => $service->name,
                                'provider' => $service->provider ? $service->provider->name : null,
                                'provider_details' => $service->provider ? [
                                    'address' => $service->provider->address,
                                    'privacy_policy_url' => $service->provider->privacy_policy_url,
                                    'cookie_policy_url' => $service->provider->cookie_policy_url,
                                    'opt_out_url' => $service->provider->opt_out_url,
                                ] : null,
                                'purpose' => $service->purpose,
                                // Consent Execution Registry v2 fields
                                'integration_type' => $service->integration_type ?? 'browser_tag',
                                'provider_key' => $service->provider_key,
                                'consent_mode_mapping' => $service->consent_mode_mapping,
                                'cookies' => $service->cookies->map(function ($cookie) {
                                    return [
                                        'name' => $cookie->name,
                                        'hostname' => $cookie->hostname,
                                        'lifetime' => $cookie->lifetime,
                                        'purpose' => $cookie->purpose,
                                    ];
                                })->toArray(),
                                'payloads' => $service->settings ? [
                                    'opt_in' => str_replace($search, $replace, $service->settings->opt_in_code ?? ''),
                                    'opt_out' => str_replace($search, $replace, $service->settings->opt_out_code ?? ''),
                                    'fallback' => str_replace($search, $replace, $service->settings->fallback_code ?? ''),
                                ] : null,
                                'integrations' => $service->settings ? [
                                    'gtm_id' => $service->settings->gtm_id,
                                    'ga_id' => $service->settings->ga_id,
                                    'pixel_id' => $service->settings->pixel_id,
                                ] : null,
                            ];
                        })
                    ];
                }),

                // Format Content Interceptors (e.g., YouTube iframes)
                'content_blockers' => $domain->contentBlockers->map(function ($blocker) {
                    return [
                        'key' => $blocker->key,
                        'name' => $blocker->name,
                        'hosts' => $blocker->hosts ?? [],
                        'preview_image' => $blocker->preview_image_url,
                        'html_code' => $blocker->html_code,
                        'css_code' => $blocker->css_code,
                        'js_code' => $blocker->js_code,
                        'text_placeholders' => $blocker->text_placeholders ?? [],
                        'service' => $blocker->service ? $blocker->service->key : null,
                        'display_mode' => $blocker->display_mode ?? 'inline',
                        'floating_position' => $blocker->floating_position,
                        'floating_icon_url' => $blocker->floating_icon_url,
                        'floating_label' => $blocker->floating_label,
                    ];
                }),

                // Format Script Blockers
                'script_blockers' => $domain->scriptBlockers
                    ->filter(fn ($blocker) => ($blocker->blocker_type ?? 'script') === 'script')
                    ->values()
                    ->map(function ($blocker) {
                    return [
                        'key' => $blocker->key,
                        'name' => $blocker->name,
                        'handles' => $blocker->handles ?? [],
                        'phrases' => $blocker->phrases ?? [],
                        'on_exist' => $blocker->on_exist,
                        'blocker_type' => $blocker->blocker_type ?? 'script',
                        'service' => $blocker->service ? $blocker->service->key : null,
                    ];
                }),

                'style_blockers' => $domain->scriptBlockers
                    ->filter(fn ($blocker) => ($blocker->blocker_type ?? 'script') === 'style')
                    ->values()
                    ->map(function ($blocker) {
                    return [
                        'key' => $blocker->key,
                        'name' => $blocker->name,
                        'handles' => $blocker->handles ?? [],
                        'phrases' => $blocker->phrases ?? [],
                        'on_exist' => $blocker->on_exist,
                        'blocker_type' => 'style',
                        'service' => $blocker->service ? $blocker->service->key : null,
                    ];
                }),

                // Generate standard callbacks if they exist inside the payloads
                'callbacks' => [
                    'onReady' => 'window.ycookiesDispatchEvent("ready")',
                    'onConsentUpdate' => 'window.ycookiesDispatchEvent("consent_update")',
                ]
            ];
        });

        if (!$configArray) {
            return response()->json(['error' => 'Invalid or inactive site_id'], 404);
        }

        // Encode the config JSON once
        $jsonResponse = json_encode($configArray);

        // ETag from content hash — enables 304 for returning visitors
        $etag = '"' . md5($jsonResponse) . '"';

        // Check If-None-Match for conditional 304 response
        $ifNoneMatch = request()->header('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=300, s-maxage=300');
        }

        return response($jsonResponse, 200)
            ->header('Content-Type', 'application/json')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=300, s-maxage=300')
            ->header('Vary', 'Accept-Encoding');
    }
}
