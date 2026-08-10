<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GvlService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GvlController extends Controller
{
    /**
     * Serve the cached Global Vendor List.
     * GET /api/tcf/gvl
     *
     * Supports optional vendor subset via ?vendors=1,2,3
     * Full GVL is ~2MB; subset mode returns only requested vendors.
     */
    public function __invoke(Request $request, GvlService $gvlService): JsonResponse|Response
    {
        $vendorIds = $request->query('vendors');

        if ($vendorIds) {
            // Subset mode: return only requested vendors
            $ids = array_filter(array_map('intval', explode(',', $vendorIds)));

            if (empty($ids) || count($ids) > 500) {
                return response()->json(['error' => 'Invalid vendor IDs (max 500)'], 400);
            }

            $subset = $gvlService->vendorSubset($ids);
            $meta = $gvlService->meta();

            return response()->json([
                'vendorListVersion' => $meta['version'] ?? null,
                'vendors' => $subset,
            ])->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
        }

        // Full GVL mode
        $gvl = $gvlService->current();

        if (!$gvl) {
            return response()->json(['error' => 'GVL unavailable'], 503);
        }

        $json = json_encode($gvl);
        $etag = '"' . md5($json) . '"';

        // Conditional 304
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
        }

        return response($json, 200)
            ->header('Content-Type', 'application/json')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=86400, s-maxage=86400')
            ->header('Vary', 'Accept-Encoding');
    }
}
