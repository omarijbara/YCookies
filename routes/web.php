<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TestClientController;

// ── Test/Debug Routes (local only) ───
// ── Main Routes ───────────────────────────────────────────
Route::get('/', function () {
    return view('welcome');
});

Route::get('/page', function () {
    // Prefer a domain with a cookie bar for proper testing
    $domain = \App\Models\Domain::where('is_active', true)
        ->whereNotNull('cookie_bar_id')
        ->first() ?? \App\Models\Domain::where('is_active', true)->first();
    $siteId = $domain ? $domain->site_id : 'missing-site-id';
    return view('test-page', compact('siteId'));
})->name('test.page');

// ── Test/Debug Routes (local only) ───
if (app()->environment('local', 'testing')) {
    Route::get('/test-signals', function () {
        $domain = \App\Models\Domain::where('name', 'ycookies.test')->first() ?? \App\Models\Domain::first();
        $siteId = $domain ? $domain->site_id : 'site_xxxx';
        $jsUrl = "/api/script/{$siteId}.js";
        return view('test-signals', compact('jsUrl'));
    });

Route::post('/logout', function (\Illuminate\Http\Request $request) {
    \Illuminate\Support\Facades\Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/');
})->name('frontend.logout');

    Route::get('/test-client', [TestClientController::class, 'index'])->name('test.client');

    Route::get('/test-bootstrapper', function () {
        // Prefer a domain that has active script blockers (so bootstrapper returns a real blocklist)
        $domain = \App\Models\Domain::where('is_active', true)
            ->whereHas('scriptBlockers', fn($q) => $q->where('is_active', true))
            ->first()
            ?? \App\Models\Domain::where('is_active', true)->first();
        $siteId = $domain ? $domain->site_id : 'missing-site-id';

        // Collect all active phrases/handles for display
        $blocklist = [];
        if ($domain) {
            foreach ($domain->scriptBlockers()->where('is_active', true)->get() as $b) {
                $blocklist = array_merge($blocklist, $b->phrases ?? [], $b->handles ?? []);
            }
        }
        $blocklist = array_values(array_unique(array_filter($blocklist)));

        return view('test-bootstrapper', compact('siteId', 'blocklist'));
    })->name('test.bootstrapper');
} // end local-only test routes

Route::get('/debug-sentry', function () {
    throw new \Exception('YCookies GlitchTip 500 Error Test! If you see this in GlitchTip, your integration is working properly.');
})->middleware('auth');


Route::get('/cron/run-scheduler', function (\Illuminate\Http\Request $request) {
    $token = config('app.scheduler_token');
    if (!$token || $request->query('token') !== $token) {
        abort(403, 'Invalid scheduler token.');
    }
    \Illuminate\Support\Facades\Artisan::call('schedule:run');
    return response('Scheduler executed.', 200);
});

// ── Debug/proxy routes (local + testing only) ───────────────────────
// These routes perform outbound HTTP fetches. In production they are
// disabled entirely to eliminate SSRF risk.
if (app()->environment('local', 'testing')) {

Route::get('/ycookies/debugger', function (\Illuminate\Http\Request $request) {
    $siteId = $request->query('site_id');
    $mode = $request->query('mode', 'test');
    $externalUrl = $request->query('url');

    // Universal mode — debug any external URL
    if ($externalUrl && $mode === 'universal') {
        return view('ycookies.debugger', [
            'domain' => null,
            'siteId' => null,
            'mode' => 'universal',
            'externalUrl' => $externalUrl,
        ]);
    }
    
    // Domain mode — existing behavior
    $domain = \App\Models\Domain::where('site_id', $siteId)->firstOrFail();
    
    return view('ycookies.debugger', [
        'domain' => $domain,
        'siteId' => $siteId,
        'mode' => $mode,
        'externalUrl' => null,
    ]);
})->name('ycookies.debugger')->middleware(\Spatie\Csp\AddCspHeaders::class);

// Server-side proxy for universal tag debugging
// Fetches external HTML and injects pixel interception scripts
Route::get('/ycookies/proxy-debug', function (\Illuminate\Http\Request $request) {
    $url = $request->query('url');
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        abort(400, 'Invalid URL provided.');
    }

    try {
        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,de;q=0.8',
            ])
            ->get($url);

        if (!$response->successful()) {
            abort(502, 'Failed to fetch the target URL (HTTP ' . $response->status() . ').');
        }

        $html = $response->body();
        $parsedUrl = parse_url($url);
        $baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

        // ── Strip frame-blocking elements from the HTML ──
        // Remove <meta> CSP tags that block iframe embedding
        $html = preg_replace('/<meta[^>]*http-equiv\s*=\s*["\']?Content-Security-Policy["\']?[^>]*>/i', '', $html);
        // Remove <meta> X-Frame-Options tags
        $html = preg_replace('/<meta[^>]*http-equiv\s*=\s*["\']?X-Frame-Options["\']?[^>]*>/i', '', $html);
        // Remove common frame-busting JavaScript patterns
        $html = preg_replace('/if\s*\(\s*(?:window\.)?top\s*!==?\s*(?:window\.)?self\s*\).*?[;}]/is', '', $html);
        $html = preg_replace('/if\s*\(\s*(?:window\.)?self\s*!==?\s*(?:window\.)?top\s*\).*?[;}]/is', '', $html);

        // Rewrite absolute URLs to our path-based proxy so that nested modules resolve correctly.
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';
        $baseOriginalUrl = $scheme . '://' . $host;
        // Use relative to document root for the proxy URL to avoid scheme mismatch issues
        $proxyBaseUrl = url('/ycookies/proxy-asset/' . $scheme . '/' . $host);

        // Inject <base> tag so relative URLs resolve correctly through the proxy
        $baseTag = '<base href="' . htmlspecialchars($proxyBaseUrl) . '/" target="_self">';

        $clearDataScript = '';
        if ($request->query('clear_data')) {
            $clearDataScript = '<script>
                try {
                    localStorage.clear();
                    sessionStorage.clear();
                    var cookies = document.cookie.split(";");
                    for (var i = 0; i < cookies.length; i++) {
                        var cookie = cookies[i];
                        var eqPos = cookie.indexOf("=");
                        var name = eqPos > -1 ? cookie.substr(0, eqPos) : cookie;
                        document.cookie = name + "=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/";
                    }
                    if (window.indexedDB && window.indexedDB.databases) {
                        window.indexedDB.databases().then(function(dbs) {
                            dbs.forEach(function(db) { window.indexedDB.deleteDatabase(db.name); });
                        });
                    }
                    // Hardcoded fallback for Borlabs Cookie specifically
                    if (window.indexedDB) {
                        window.indexedDB.deleteDatabase("borlabs-cookie");
                        window.indexedDB.deleteDatabase("borlabs-cookie-tcf");
                    }
                    console.log("YCookies Proxy: Local storage, session storage, indexedDB, and cookies cleared.");
                } catch(e) { console.error("YCookies Proxy: Error clearing data:", e); }
            </script>';
        }

        // The universal interception script — must run BEFORE any other scripts
        $interceptScript = view('ycookies.partials.universal-interceptor', ['externalUrl' => $url])->render();

        // Inject: base tag + clear data script + interception script right after <head>
        $injection = $baseTag . "\n" . $clearDataScript . "\n" . $interceptScript;

        // Function to perform exhaustive URL replacement
        $replaceUrls = function($content) use ($parsedUrl, $proxyBaseUrl) {
            $scheme = $parsedUrl['scheme'] ?? 'https';
            $host = $parsedUrl['host'] ?? '';
            
            $replacements = [
                $scheme . '://' . $host => $proxyBaseUrl,
                $scheme . ':\/\/' . $host => str_replace('/', '\/', $proxyBaseUrl),
                '//' . $host => str_replace(['http:', 'https:'], '', $proxyBaseUrl),
                '\/\/' . $host => str_replace('/', '\/', str_replace(['http:', 'https:'], '', $proxyBaseUrl)),
            ];
            
            return str_replace(array_keys($replacements), array_values($replacements), $content);
        };

        // Replace all URL versions
        $html = $replaceUrls($html);

        // INJECT AFTER REPLACEMENT so the interceptor's own targetUrl string is not mangled
        if (stripos($html, '<head>') !== false) {
            $html = preg_replace('/<head>/i', '<head>' . $injection, $html, 1);
        } elseif (stripos($html, '<head ') !== false) {
            $html = preg_replace('/<head(\s[^>]*)>/i', '<head$1>' . $injection, $html, 1);
        } elseif (stripos($html, '<html') !== false) {
            $html = preg_replace('/<html([^>]*)>/i', '<html$1><head>' . $injection . '</head>', $html, 1);
        } else {
            $html = $injection . $html;
        }

        $allowedOrigin = $request->header('Origin') ?: config('app.url');

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "frame-ancestors 'self'")
            ->header('Access-Control-Allow-Origin', $allowedOrigin);
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        $message = str_contains($e->getMessage(), 'Could not resolve host') || str_contains($e->getMessage(), 'cURL error 6')
            ? 'No internet connection or target host could not be resolved. Please check your network connection and ensure the URL is correct.'
            : 'Could not connect to the target URL: ' . $e->getMessage();
            
        return response(view('ycookies.partials.proxy-error', ['url' => $url, 'message' => $message])->render(), 502)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    } catch (\Exception $e) {
        return response(view('ycookies.partials.proxy-error', ['url' => $url, 'message' => 'An error occurred while proxying the request: ' . $e->getMessage()])->render(), 502)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
})->name('ycookies.proxy-debug')->middleware('auth');

// Generic asset proxy to bypass CORS for scripts and API requests
Route::any('/ycookies/proxy-asset/{scheme}/{host}/{path?}', function (\Illuminate\Http\Request $request, $scheme, $host, $path = '') {
    // ── SSRF Protection: Block internal/private network access ──
    $blockedPatterns = ['127.0.0.1', 'localhost', '0.0.0.0', '169.254.169.254', '10.', '172.16.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.', '192.168.', '[::1]'];
    foreach ($blockedPatterns as $blocked) {
        if (str_starts_with($host, $blocked) || $host === $blocked) {
            abort(403, 'Access to internal networks is not allowed.');
        }
    }
    // Only allow http/https schemes
    if (!in_array($scheme, ['http', 'https'], true)) {
        abort(400, 'Invalid URL scheme.');
    }

    $url = $scheme . '://' . $host . ($path ? '/' . $path : '');
    $queryString = $request->getQueryString();
    if ($queryString) {
        $url .= '?' . $queryString;
    }

    try {
        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->withHeaders([
                'User-Agent' => $request->header('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
                'Accept' => $request->header('Accept', '*/*'),
                'Referer' => $scheme . '://' . $host . '/',
            ])
            ->send($request->method(), $url, [
                'body' => $request->getContent()
            ]);

        $contentType = $response->header('Content-Type');
        $body = $response->body();

        // If it's a HTML, JS file or JSON, rewrite absolute URLs
        $ctLower = strtolower(is_array($contentType) ? implode(',', $contentType) : (string)$contentType);
        if ($ctLower && (str_contains($ctLower, 'html') || str_contains($ctLower, 'javascript') || str_contains($ctLower, 'json') || str_contains($ctLower, 'text/'))) {
            $proxyBaseUrl = url('/ycookies/proxy-asset/' . $scheme . '/' . $host);
            
            $replacements = [
                $scheme . '://' . $host => $proxyBaseUrl,
                $scheme . ':\/\/' . $host => str_replace('/', '\/', $proxyBaseUrl),
                '//' . $host => str_replace(['http:', 'https:'], '', $proxyBaseUrl),
                '\/\/' . $host => str_replace('/', '\/', str_replace(['http:', 'https:'], '', $proxyBaseUrl)),
            ];
            
            $body = str_replace(array_keys($replacements), array_values($replacements), $body);
            
            // Aggressive fallback specifically for Borlabs dynamic imports matching any remaining host domain string inside JSON payloads
            $body = preg_replace('/"([^"]*?)' . preg_quote($host, '/') . '([^"]*?)"/i', '"' . str_replace('/', '\/', $proxyBaseUrl) . '$2"', $body);
        }

        // Restrict CORS to the requesting origin instead of wildcard
        $allowedOrigin = $request->header('Origin') ?: config('app.url');

        return response($body, $response->status())
            ->header('Content-Type', $contentType ?? 'application/octet-stream')
            ->header('Access-Control-Allow-Origin', $allowedOrigin)
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
    } catch (\Exception $e) {
        abort(502, 'Asset proxy failed: ' . $e->getMessage());
    }
})->where('path', '.*')->name('ycookies.proxy-asset')->middleware('auth')->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

} // end debug/proxy routes (local + testing only)

Route::get('/ycookies/preview', function (\Illuminate\Http\Request $request) {
    $siteId = $request->query('site_id');

    // Simulate the API Controller response locally for the preview
    $domain = \App\Models\Domain::where('site_id', $siteId)
        ->with([
            'cookieBar', // Load the related cookie bar
            'cookieGroups' => function ($q) {
                $q->orderBy('sort_order');
            },
            'cookieGroups.services' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            },
            'cookieGroups.services.settings',
            'cookieGroups.services.provider',
            'cookieGroups.services.cookies',
            'contentBlockers'
        ])
        ->firstOrFail();

    $config = [
        'version' => '1.0.preview',
        'domain' => $domain->name,
        'theme' => $domain->cookieBar?->theme_settings ?? [],
        'translations' => $domain->cookieBar?->translations ?? [],
        'ui_config' => call_user_func(function () use ($domain) {
            // Recursively strip null values so they don't override valid defaults
            $filterNulls = function (array $arr) use (&$filterNulls): array {
                $clean = [];
                foreach ($arr as $key => $value) {
                    if ($value === null) continue;
                    $clean[$key] = is_array($value) ? $filterNulls($value) : $value;
                }
                return $clean;
            };

            $defaults = [
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
                    'show_accept_essential' => false,
                    'show_settings' => true,
                    'show_save_consent' => false,
                    'show_accept_essential_only' => false,
                ]
            ];

            return array_replace_recursive(
                $defaults,
                $filterNulls($domain->cookieBar?->ui_config ?? []),
                $filterNulls($domain->ui_config ?? []),
                $filterNulls($domain->cookieBar?->theme_settings ?? [])
            );
        }),
        'tcm_config' => $domain->tcm_config ?? [],
        'geo_rules' => $domain->geo_rules ?? [],
        'cookie_groups' => $domain->cookieGroups->map(function ($group) {
            return [
                'key' => $group->key,
                'name' => $group->name,
                'description' => $group->description,
                'is_required' => $group->is_required,
                'is_preselected' => $group->is_preselected,
                'services' => $group->services->map(function ($service) {
                    return [
                        'key' => $service->key,
                        'name' => $service->name,
                        'provider' => $service->provider ? $service->provider->name : null,
                        'provider_details' => $service->provider ? [
                            'address' => $service->provider->address,
                            'privacy_policy_url' => $service->provider->privacy_policy_url,
                        ] : null,
                        'purpose' => $service->purpose,
                        'cookies' => $service->cookies->map(function ($cookie) {
                            return [
                                'name' => $cookie->name,
                                'hostname' => $cookie->hostname,
                                'lifetime' => $cookie->lifetime,
                            ];
                        })->toArray(),
                    ];
                })
            ];
        }),
        'content_blockers' => []
    ];

    return view('ycookies.preview-iframe', [
        'config' => $config,
        'preview_mode' => true
    ]);
})->name('ycookies.preview')->middleware([
    \Spatie\Csp\AddCspHeaders::class,
    \BezhanSalleh\LanguageSwitch\Http\Middleware\SwitchLanguageLocale::class,
]);

// Visual Node Proxy Health Page
Route::get('/proxy/up', function () {
    $startTime = microtime(true);
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(5)->get('http://node-proxy:80/health');
        $latency = round((microtime(true) - $startTime) * 1000);
        
        if ($response->successful()) {
            $data = $response->json();
            return view('proxy-up', [
                'status' => 'ok',
                'uptime' => $data['uptime'] ?? 0,
                'latency' => $latency
            ]);
        }
        
        return response()->view('proxy-up', [
            'status' => 'error',
            'message' => 'Node Proxy responded with HTTP ' . $response->status()
        ], 502);

    } catch (\Throwable $e) {
        return response()->view('proxy-up', [
            'status' => 'error',
            'message' => 'Node Proxy Unreachable: ' . $e->getMessage()
        ], 502);
    }
})->name('proxy.up');

// Invitation acceptance route
Route::get('/invitations/accept/{token}', [\App\Http\Controllers\InvitationController::class, 'accept'])
    ->name('invitations.accept');

if (app()->environment('local', 'testing')) {
    Route::get('/test-gcm', function () { return view('test-gcm'); });
}

