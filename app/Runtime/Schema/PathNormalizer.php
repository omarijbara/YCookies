<?php

declare(strict_types=1);

namespace App\Runtime\Schema;

/**
 * PathNormalizer — Deterministic URL path normalization and route matching.
 *
 * Normalization order (locked by architecture spec):
 *   1. Parse URL → take pathname only
 *   2. Ignore query string and fragment for overlay selection
 *   3. Preserve original case
 *   4. Normalize empty path to '/'
 *   5. Remove trailing slash unless path is exactly '/'
 *   6. If domain defines locale prefixes, strip leading locale → expose as request.locale
 *   7. Match against route index: exact > longest * > longest ** > priority > lexical overlay ID
 *
 * IMPORTANT: The Node.js implementation (services/proxy/route-resolver.js)
 * MUST produce identical results. Cross-language tests verify this.
 */
final class PathNormalizer
{
    /**
     * Normalize a URL path according to the locked normalization order.
     *
     * @param string $url         Full URL or path
     * @param array  $localePrefixes  Optional locale prefixes the domain defines (e.g., ['de', 'fr', 'en'])
     * @return NormalizedPath
     */
    public static function normalize(string $url, array $localePrefixes = []): NormalizedPath
    {
        // Step 1: Parse URL and take pathname only
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';

        // Step 2: Query string and fragment are ignored for overlay selection
        // (already handled by parse_url — we only use 'path')

        // Step 3: Preserve original case (no lowercasing)

        // Step 4: Normalize empty path to '/'
        if ($path === '' || $path === null) {
            $path = '/';
        }

        // Step 5: Remove trailing slash unless path is exactly '/'
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // Step 6: Strip leading locale prefix if defined
        $locale = null;
        if (!empty($localePrefixes)) {
            $locale = self::stripLocalePrefix($path, $localePrefixes);
            if ($locale !== null) {
                // Find the actual locale segment in the path (might differ in case)
                $segments = explode('/', ltrim($path, '/'), 2);
                $actualSegment = $segments[0]; // e.g., 'DE' from '/DE/about'
                $localeSegment = '/' . $actualSegment;
                if (strcasecmp($path, $localeSegment) === 0) {
                    // Path is exactly the locale segment (e.g., /DE or /de)
                    $path = '/';
                } elseif (stripos($path, $localeSegment . '/') === 0) {
                    $path = substr($path, strlen($localeSegment));
                    // Re-apply step 5 after stripping
                    if ($path !== '/' && str_ends_with($path, '/')) {
                        $path = rtrim($path, '/');
                    }
                    if ($path === '') {
                        $path = '/';
                    }
                }
            }
        }

        return new NormalizedPath($path, $locale);
    }

    /**
     * Strip and return the leading locale prefix if it matches any defined prefix.
     *
     * Matches patterns like: /de/page, /fr, /en-US/page
     * Only matches the first path segment.
     */
    /**
     * Check if the first path segment matches a locale prefix.
     * Returns [canonical_prefix, actual_segment] or null.
     */
    private static function stripLocalePrefix(string $path, array $localePrefixes): ?string
    {
        if ($path === '/' || $path === '') {
            return null;
        }

        // Extract first segment: /de/page → 'de', /DE/about → 'DE'
        $segments = explode('/', ltrim($path, '/'), 2);
        $firstSegment = $segments[0];
        $firstSegmentLower = strtolower($firstSegment);

        foreach ($localePrefixes as $prefix) {
            if (strtolower($prefix) === $firstSegmentLower) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Match a normalized path against a route index.
     *
     * Resolution precedence (highest first):
     *   1. Exact match
     *   2. Longest wildcard (*) match
     *   3. Longest globstar (**) match
     *   4. Higher explicit priority
     *   5. Lexically first overlay_id (tie-break)
     *
     * Returns the overlay_id of the best match, or null for base-only.
     *
     * @param string $normalizedPath  The normalized path to match
     * @param array  $routeIndex      Route index artifact (contains 'routes' key)
     * @return RouteMatch|null
     */
    public static function matchRoute(string $normalizedPath, array $routeIndex): ?RouteMatch
    {
        $routes = $routeIndex['routes'] ?? [];

        if (empty($routes)) {
            return null;
        }

        $candidates = [];

        foreach ($routes as $entry) {
            $pattern = $entry['pattern'] ?? '';
            $matchType = $entry['match_type'] ?? ManifestSchema::MATCH_DEFAULT;
            $overlayId = $entry['overlay_id'] ?? '';
            $priority = $entry['priority'] ?? 0;

            $matched = match ($matchType) {
                ManifestSchema::MATCH_EXACT    => self::matchExact($normalizedPath, $pattern),
                ManifestSchema::MATCH_WILDCARD => self::matchWildcard($normalizedPath, $pattern),
                ManifestSchema::MATCH_GLOBSTAR => self::matchGlobstar($normalizedPath, $pattern),
                default => false,
            };

            if ($matched) {
                $candidates[] = [
                    'overlay_id'  => $overlayId,
                    'match_type'  => $matchType,
                    'pattern'     => $pattern,
                    'priority'    => $priority,
                    'specificity' => strlen($pattern),
                ];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Sort by precedence
        usort($candidates, function ($a, $b) {
            // 1. Match type precedence (higher wins)
            $precA = ManifestSchema::MATCH_PRECEDENCE[$a['match_type']] ?? 0;
            $precB = ManifestSchema::MATCH_PRECEDENCE[$b['match_type']] ?? 0;
            if ($precA !== $precB) {
                return $precB - $precA; // descending
            }

            // 2. Pattern specificity — longer pattern wins (more specific)
            if ($a['specificity'] !== $b['specificity']) {
                return $b['specificity'] - $a['specificity']; // descending
            }

            // 3. Explicit priority (higher wins)
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] - $a['priority']; // descending
            }

            // 4. Lexical overlay_id (tie-break, ascending)
            return strcmp($a['overlay_id'], $b['overlay_id']);
        });

        $best = $candidates[0];
        return new RouteMatch($best['overlay_id'], $best['match_type'], $best['pattern']);
    }

    /**
     * Exact match: path must equal pattern exactly.
     */
    private static function matchExact(string $path, string $pattern): bool
    {
        return $path === $pattern;
    }

    /**
     * Single-segment wildcard (*): matches exactly one path segment at the
     * wildcard position.
     *
     * /blog/* matches /blog/hello but NOT /blog/hello/world
     */
    private static function matchWildcard(string $path, string $pattern): bool
    {
        // Find the position of '*'
        $starPos = strpos($pattern, '*');
        if ($starPos === false) {
            return $path === $pattern;
        }

        $prefix = substr($pattern, 0, $starPos);
        $suffix = substr($pattern, $starPos + 1);

        // Path must start with prefix
        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        // Path must end with suffix (if any)
        if ($suffix !== '' && !str_ends_with($path, $suffix)) {
            return false;
        }

        // The matched segment (between prefix and suffix) must not contain '/'
        $matchedPart = substr($path, strlen($prefix));
        if ($suffix !== '') {
            $matchedPart = substr($matchedPart, 0, -strlen($suffix));
        }

        // Must match exactly one segment (no slashes)
        return $matchedPart !== '' && !str_contains($matchedPart, '/');
    }

    /**
     * Globstar (**): matches zero or more path segments.
     *
     * /docs/** matches /docs, /docs/api, /docs/api/v2/endpoints
     */
    private static function matchGlobstar(string $path, string $pattern): bool
    {
        $globPos = strpos($pattern, '**');
        if ($globPos === false) {
            return $path === $pattern;
        }

        $prefix = substr($pattern, 0, $globPos);
        $suffix = substr($pattern, $globPos + 2);

        // Path must start with prefix
        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        // Path must end with suffix (if any)
        if ($suffix !== '' && !str_ends_with($path, $suffix)) {
            return false;
        }

        // Globstar matches anything (including empty), so if prefix and suffix match, we're good
        return true;
    }
}
