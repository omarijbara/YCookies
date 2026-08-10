<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ConsentConfigController;
use App\Http\Controllers\Api\ScriptDeliveryController;
use App\Http\Controllers\Api\ConsentIngestController;
use App\Http\Controllers\Api\ConsentHubController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

use App\Http\Controllers\Api\BootstrapperController;
use App\Http\Controllers\Api\ProxyConfigController;
use App\Http\Controllers\Api\ObservabilityController;
use App\Http\Controllers\Api\GvlController;
use App\Http\Controllers\Api\TcStringIngestController;

use App\Http\Controllers\Api\ManifestProjectionController;
use App\Http\Controllers\Api\ProxyErrorController;

Route::get('/healthz', function () {
    $checks = [];
    $healthy = true;

    // 1. Database connectivity
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (\Throwable $e) {
        $checks['database'] = 'fail: ' . $e->getMessage();
        $healthy = false;
    }

    // 2. Redis connectivity
    try {
        $pong = \Illuminate\Support\Facades\Redis::connection('default')->ping();
        $checks['redis'] = ($pong === true || $pong === 'PONG' || (string) $pong === '+PONG') ? 'ok' : 'fail';
        if ($checks['redis'] !== 'ok') $healthy = false;
    } catch (\Throwable $e) {
        $checks['redis'] = 'fail: ' . $e->getMessage();
        $healthy = false;
    }

    // 3. Storage writable
    try {
        $testFile = storage_path('app/.health_check');
        file_put_contents($testFile, 'ok');
        unlink($testFile);
        $checks['storage'] = 'ok';
    } catch (\Throwable $e) {
        $checks['storage'] = 'fail: ' . $e->getMessage();
        $healthy = false;
    }

    return response()->json([
        'status' => $healthy ? 'healthy' : 'degraded',
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $healthy ? 200 : 503);
})->name('api.health');

// Proxy error ingestion (HMAC protected)
Route::post('/proxy-errors', [ProxyErrorController::class, 'ingest'])
    ->name('api.proxy.errors');

// Delivery the core config JSON payload (manifest-projected when enabled, legacy fallback)
Route::get('/config/{site_id}', ManifestProjectionController::class)
    ->middleware([\App\Http\Middleware\ManifestDiffValidator::class, 'throttle:api-tenant'])
    ->name('api.consent.config');

// Deliver the synchronous bootstrapper (dynamic blocklist from ScriptBlockers)
Route::get('/boot/{site_id}.js', BootstrapperController::class)
    ->middleware('throttle:api-tenant')
    ->name('api.consent.boot');

// Deliver the Minified JS + Dynamic Config payload.
// Uses a strict UUID regex to avoid collision with chunks.
Route::get('/script/{site_id}.js', ScriptDeliveryController::class)
    ->middleware('throttle:api-tenant')
    ->where('site_id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9]+')
    ->name('api.consent.script');

// Fallback: Serve Vite chunks if requested through the api/script prefix.
// This route catches hashed chunks (e.g. tcf-core-hash.js) that didn't match the UUID above.
Route::get('/script/{chunk}.js', function ($chunk) {
    if (!str_contains($chunk, '-')) {
        return response()->json(['error' => 'Not a chunk'], 400);
    }
    $path = public_path("build/assets/{$chunk}.js");
    if (file_exists($path)) {
        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
    return response()->json(['error' => 'Chunk not found'], 404);
})->where('chunk', '.*-.*');

// Central Consent Hub for Cross-Domain Syncing
Route::get('/hub/{site_id}', [ConsentHubController::class, 'serve'])
    ->middleware('throttle:api-tenant')
    ->name('api.consent.hub');

// Ingest beacon pings containing user consent choices
Route::post('/log-consent', [ConsentIngestController::class, 'log'])->name('api.consent.log')->middleware('throttle:api-tenant');

// Internal readiness/polling probe for the Node proxy.
Route::match(['GET', 'HEAD'], '/proxy-config/healthcheck', fn () => response()->noContent());

// Node proxy service: fetch domain config for proxying
Route::get('/proxy-config/{host}', [ProxyConfigController::class, 'show'])
    ->middleware('proxy.config.signature')
    ->name('api.proxy.config');

// Node proxy service: ingest edge metrics batch
Route::post('/metrics/batch', [ObservabilityController::class, 'ingest'])
    ->middleware('proxy.hmac')
    ->name('api.metrics.ingest');

// Browser RUM beacon: client-side telemetry (no HMAC — browser originated, rate-limited)
Route::post('/rum/beacon', [ObservabilityController::class, 'ingestRum'])
    ->middleware('throttle:60,1')
    ->name('api.rum.ingest');

// Visitor discovery beacon: client reports blocked third-party resources
Route::post('/discovery/beacon', [\App\Http\Controllers\Api\DiscoveryController::class, 'beacon'])
    ->middleware('throttle:30,1')
    ->name('api.discovery.beacon');

// IAB TCF v2.2: serve the Global Vendor List (cached 24h)
Route::get('/tcf/gvl', GvlController::class)->name('api.tcf.gvl');

// IAB TCF v2.2: ingest TC strings from client-side CMP for audit
Route::post('/tcf/record', TcStringIngestController::class)
    ->middleware('throttle:60,1')
    ->name('api.tcf.record');

// Admin/installer: sync proxy domains to Coolify docker_compose_domains for Traefik routing + SSL
Route::post('/proxy/sync-domains', function () {
    $secret = request()->header('X-Proxy-Secret');
    $expected = config('services.proxy.shared_secret');
    if (empty($secret) || !hash_equals($expected, $secret)) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }
    $result = app(\App\Services\CoolifyService::class)->syncDomains();
    return response()->json($result);
})->name('api.proxy.sync-domains');

// Internal Node Proxy Health Check (Proxied via Laravel to avoid using customer domains for monitoring)
Route::get('/proxy/health', function () {
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(3)->get('http://node-proxy:80/health');
        return response($response->body(), $response->status())->header('Content-Type', 'application/json');
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Node Proxy Unreachable',
            'error' => $e->getMessage()
        ], 502);
    }
});
