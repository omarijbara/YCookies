<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GtmDownloaderService
{
    /**
     * Download the GTM script and save it locally.
     *
     * @param string $gtmId The Google Tag Manager ID (e.g., GTM-XXXXXXX)
     * @return bool True if successful, false otherwise
     */
    public static function download(string $gtmId): bool
    {
        if (empty($gtmId)) {
            return false;
        }

        try {
            // Clean the ID just in case
            $gtmId = trim($gtmId);
            
            $url = "https://www.googletagmanager.com/gtm.js?id={$gtmId}";
            
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer' => config('app.url')
                ])
                ->timeout(10)
                ->get($url);
            
            if ($response->successful()) {
                $content = $response->body();
                
                // Define the storage path
                $path = "ycookies/gtm/{$gtmId}.js";
                
                // Save to public disk
                Storage::disk('public')->put($path, $content);
                
                return true;
            } else {
                Log::error("Failed to download GTM script for ID: {$gtmId}. Status: " . $response->status());
            }
        } catch (\Exception $e) {
            Log::error("Exception while downloading GTM script for ID {$gtmId}: " . $e->getMessage());
        }

        return false;
    }
}
