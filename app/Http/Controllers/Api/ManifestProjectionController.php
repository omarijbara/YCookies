<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Runtime\Consumer\ManifestConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ManifestProjectionController — Projects manifest base artifact into
 * the legacy /api/config/{site_id} JSON shape.
 *
 * When manifest mode is active for a domain, this controller reads
 * the published revision's base artifact and projects it into the
 * exact JSON structure that ConsentConfigController returns.
 *
 * Request-time fields (visitor_country, current_is_rtl, resolved
 * translations) are injected at read time — they are NOT stored
 * in the manifest because they vary per-request.
 *
 * If manifest mode is not active or no revision exists, this delegates
 * to the legacy ConsentConfigController seamlessly.
 */
class ManifestProjectionController extends Controller
{
    public function __invoke(Request $request, $siteId)
    {
        if (!$siteId) {
            return response()->json(['error' => 'Missing site_id parameter'], 400);
        }

        // Check if this domain has manifest mode enabled
        $domain = Domain::where('site_id', $siteId)
            ->where('is_active', true)
            ->first();

        if (!$domain) {
            return response()->json(['error' => 'Invalid or inactive site_id'], 404);
        }

        // If manifest mode is not enabled, delegate to legacy controller
        if (!$domain->manifest_enabled) {
            return app(ConsentConfigController::class)->__invoke($request, $siteId);
        }

        // Resolve language (same logic as ConsentConfigController)
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

        app()->setLocale($lang);

        // Use the shared ManifestConfigService for projection
        $service = app(ManifestConfigService::class);
        $configArray = $service->resolveConfig($domain, $lang);

        if (!$configArray) {
            // No published revision yet — fall back to legacy
            Log::info("ManifestProjection: no revision for {$domain->name}, falling back to legacy");
            return app(ConsentConfigController::class)->__invoke($request, $siteId);
        }

        // Encode once and return with same caching headers as legacy
        $jsonResponse = json_encode($configArray);
        $etag = '"' . md5($jsonResponse) . '"';

        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=300, s-maxage=300');
        }

        $revisionNumber = $service->getRevisionNumber($domain);

        return response($jsonResponse, 200)
            ->header('Content-Type', 'application/json')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=300, s-maxage=300')
            ->header('Vary', 'Accept-Encoding')
            ->header('X-Manifest-Revision', (string) ($revisionNumber ?? 0));
    }
}
