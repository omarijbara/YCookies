<?php

namespace App\Support;

/**
 * RouteFingerprint — Normalizes URL paths into stable route patterns.
 *
 * Server-side mirror of services/proxy/route-fingerprint.js.
 * Used as a safety net if the Node proxy sends raw paths instead
 * of pre-fingerprinted patterns.
 *
 * Examples:
 *   /checkout/12345            → /checkout/:id
 *   /api/orders/9f1c.../items  → /api/orders/:token/items
 *   /search?q=shoes&page=2    → /search
 *   /                          → /
 */
class RouteFingerprint
{
    // UUID: 8-4-4-4-12 hex
    const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    // Hex hash: 16+ hex chars
    const HEX_HASH_PATTERN = '/^[0-9a-f]{16,}$/i';

    // Pure numeric
    const NUMERIC_PATTERN = '/^\d+$/';

    // Base64-ish token: 16+ alphanumeric mixed case
    const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{16,}$/';

    // Max segments before collapsing
    const MAX_SEGMENTS = 8;

    /**
     * Fingerprint a raw URL path into a stable route pattern.
     */
    public static function normalize(?string $rawPath): string
    {
        if (!$rawPath || $rawPath === '/') {
            return '/';
        }

        // Strip query string
        $path = explode('?', $rawPath)[0];

        // Normalize slashes
        $path = preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');

        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = array_filter(explode('/', $path), fn ($s) => $s !== '');

        // Cap segments
        if (count($segments) > self::MAX_SEGMENTS) {
            $segments = array_merge(
                array_slice($segments, 0, self::MAX_SEGMENTS),
                ['*']
            );
        }

        $fingerprinted = array_map([self::class, 'classifySegment'], $segments);

        return '/' . implode('/', $fingerprinted);
    }

    /**
     * Classify a single path segment.
     */
    public static function classifySegment(string $segment): string
    {
        if ($segment === '*') return '*';

        // UUID
        if (preg_match(self::UUID_PATTERN, $segment)) return ':uuid';

        // Numeric
        if (preg_match(self::NUMERIC_PATTERN, $segment)) return ':id';

        // Hex hash
        if (preg_match(self::HEX_HASH_PATTERN, $segment)) return ':hash';

        // Long mixed-case token
        if (preg_match(self::TOKEN_PATTERN, $segment)
            && preg_match('/[A-Z]/', $segment)
            && preg_match('/[a-z]/', $segment)) {
            return ':token';
        }

        return $segment;
    }
}
