<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GvlService
{
    /**
     * IAB GVL v3 endpoint (TCF v2.2 compatible).
     */
    const GVL_URL = 'https://vendor-list.consensu.org/v3/vendor-list.json';

    /**
     * Cache key for the full GVL.
     */
    const CACHE_KEY = 'tcf:gvl:current';

    /**
     * Cache key for GVL metadata (version, checksum, last fetched).
     */
    const META_KEY = 'tcf:gvl:meta';

    /**
     * Cache TTL: 24 hours.
     */
    const TTL_HOURS = 24;

    /**
     * Get the current GVL, fetching from cache or remote.
     */
    public function current(): ?array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(self::TTL_HOURS), function () {
            return $this->fetchFromRemote();
        });
    }

    /**
     * Force-refresh the GVL cache from the IAB endpoint.
     */
    public function refresh(): ?array
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::META_KEY);

        $gvl = $this->fetchFromRemote();

        if ($gvl) {
            Cache::put(self::CACHE_KEY, $gvl, now()->addHours(self::TTL_HOURS));
            Cache::put(self::META_KEY, [
                'version' => $gvl['vendorListVersion'] ?? null,
                'checksum' => md5(json_encode($gvl)),
                'fetched_at' => now()->toIso8601String(),
                'vendor_count' => isset($gvl['vendors']) ? count($gvl['vendors']) : 0,
            ], now()->addHours(self::TTL_HOURS));
        }

        return $gvl;
    }

    /**
     * Get a subset of vendors by their IDs.
     * Useful for serving lightweight payloads to the client.
     */
    public function vendorSubset(array $vendorIds): array
    {
        $gvl = $this->current();

        if (!$gvl || !isset($gvl['vendors'])) {
            return [];
        }

        $subset = [];
        foreach ($vendorIds as $id) {
            $id = (string) $id;
            if (isset($gvl['vendors'][$id])) {
                $subset[$id] = $gvl['vendors'][$id];
            }
        }

        return $subset;
    }

    /**
     * Get GVL metadata (version, checksum, vendor count).
     */
    public function meta(): ?array
    {
        return Cache::get(self::META_KEY);
    }

    /**
     * Fetch the GVL JSON from the IAB endpoint.
     */
    protected function fetchFromRemote(): ?array
    {
        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->get(self::GVL_URL);

            if ($response->successful()) {
                $gvl = $response->json();

                if (!isset($gvl['vendorListVersion'])) {
                    Log::warning('[GvlService] GVL response missing vendorListVersion');
                    return null;
                }

                Log::info('[GvlService] GVL fetched successfully', [
                    'version' => $gvl['vendorListVersion'],
                    'vendors' => isset($gvl['vendors']) ? count($gvl['vendors']) : 0,
                ]);

                return $gvl;
            }

            Log::error('[GvlService] GVL fetch failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 200),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('[GvlService] GVL fetch exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
