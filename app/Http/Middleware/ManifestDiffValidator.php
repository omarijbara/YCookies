<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ManifestDiffValidator — Shadow-mode middleware that compares
 * manifest-projected output against legacy controller output.
 *
 * Activation: Set env MANIFEST_DIFF_MODE=shadow
 *
 * When active, this middleware:
 * 1. Intercepts the response from ManifestProjectionController
 * 2. Runs the legacy ConsentConfigController for the same request
 * 3. Compares the two outputs (ignoring request-time fields)
 * 4. Logs any differences to the 'runtime_drift' channel
 *
 * WARNING: This doubles DB cost per request. Use only during
 * canary validation, then disable.
 */
class ManifestDiffValidator
{
    /**
     * Fields that are intentionally different between manifest and legacy
     * because they are request-time derived and not stored in the manifest.
     */
    private const IGNORED_FIELDS = [
        'visitor_country',
        '_manifest_revision',
        'version',
    ];

    public function handle(Request $request, Closure $next)
    {
        // Only activate in shadow mode
        if (config('runtime.diff_mode') !== 'shadow') {
            return $next($request);
        }

        // Run the primary handler (ManifestProjectionController)
        $primaryResponse = $next($request);

        // Only diff successful JSON responses
        if ($primaryResponse->getStatusCode() !== 200) {
            return $primaryResponse;
        }

        // Check if this response was actually served from manifest
        if (!$primaryResponse->headers->has('X-Manifest-Revision')) {
            return $primaryResponse; // Legacy fallback — no diff needed
        }

        // Run the legacy controller for comparison
        try {
            $siteId = $request->route('site_id');
            $legacyController = app(\App\Http\Controllers\Api\ConsentConfigController::class);
            $legacyResponse = $legacyController->__invoke($request, $siteId);

            if ($legacyResponse->getStatusCode() !== 200) {
                Log::channel('runtime_drift')->warning('Legacy controller returned non-200', [
                    'site_id' => $siteId,
                    'status'  => $legacyResponse->getStatusCode(),
                ]);
                return $primaryResponse;
            }

            $manifestData = json_decode($primaryResponse->getContent(), true);
            $legacyData = json_decode($legacyResponse->getContent(), true);

            // Remove ignored fields before comparison
            foreach (self::IGNORED_FIELDS as $field) {
                unset($manifestData[$field], $legacyData[$field]);
            }

            // Deep comparison
            $diffs = $this->deepDiff($manifestData, $legacyData);

            if (!empty($diffs)) {
                Log::channel('runtime_drift')->error('Manifest drift detected', [
                    'site_id'   => $siteId,
                    'revision'  => $primaryResponse->headers->get('X-Manifest-Revision'),
                    'diff_count' => count($diffs),
                    'diffs'     => array_slice($diffs, 0, 20), // Cap at 20 to prevent log explosion
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('runtime_drift')->error('Shadow diff failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $primaryResponse;
    }

    /**
     * Recursively diff two arrays and return list of differences.
     */
    protected function deepDiff($a, $b, string $path = ''): array
    {
        $diffs = [];

        if (!is_array($a) || !is_array($b)) {
            if ($a !== $b) {
                $diffs[] = [
                    'path'     => $path ?: '(root)',
                    'manifest' => $this->truncate($a),
                    'legacy'   => $this->truncate($b),
                ];
            }
            return $diffs;
        }

        $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));

        foreach ($allKeys as $key) {
            $childPath = $path ? "{$path}.{$key}" : (string) $key;

            if (!array_key_exists($key, $a)) {
                $diffs[] = ['path' => $childPath, 'manifest' => '(missing)', 'legacy' => $this->truncate($b[$key])];
            } elseif (!array_key_exists($key, $b)) {
                $diffs[] = ['path' => $childPath, 'manifest' => $this->truncate($a[$key]), 'legacy' => '(missing)'];
            } else {
                $diffs = array_merge($diffs, $this->deepDiff($a[$key], $b[$key], $childPath));
            }
        }

        return $diffs;
    }

    /**
     * Truncate values for log readability.
     */
    protected function truncate($value, int $maxLen = 100): string
    {
        if (is_null($value)) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_array($value)) {
            $json = json_encode($value);
            return strlen($json) > $maxLen ? substr($json, 0, $maxLen) . '...' : $json;
        }
        $str = (string) $value;
        return strlen($str) > $maxLen ? substr($str, 0, $maxLen) . '...' : $str;
    }
}
