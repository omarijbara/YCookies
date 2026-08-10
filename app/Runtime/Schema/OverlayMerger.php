<?php

declare(strict_types=1);

namespace App\Runtime\Schema;

/**
 * OverlayMerger — Deterministic base + overlay merge.
 *
 * Merge rules:
 *   1. Only overlay-eligible fields may appear in overlays
 *   2. Overlay keys win over base keys (deep merge for nested objects)
 *   3. Array fields (sequential/list arrays like cookie_groups) are REPLACED entirely
 *   4. Null values in overlay explicitly REMOVE the base key
 *   5. No overlay chaining — base + zero/one overlay only
 *   6. Base-only fields are always preserved from the base
 *
 * IMPORTANT: Same merge logic must be implemented in Node.js
 * (services/proxy/overlay-merger.js) and verified by cross-language tests.
 */
final class OverlayMerger
{
    /**
     * Merge a base artifact with zero or one overlay.
     *
     * @param array      $base     The full base artifact
     * @param array|null $overlay  Sparse overlay (or null for base-only)
     * @return array The merged effective artifact
     */
    public static function merge(array $base, ?array $overlay): array
    {
        if ($overlay === null || empty($overlay)) {
            return $base;
        }

        $result = $base;

        foreach ($overlay as $key => $overlayValue) {
            // Skip the overlay_id meta field — not a runtime config field
            if ($key === 'overlay_id') {
                continue;
            }

            // Base-only fields cannot be overridden
            if (in_array($key, ManifestSchema::BASE_ONLY_FIELDS, true)) {
                continue;
            }

            // Only overlay-eligible fields are applied
            if (!in_array($key, ManifestSchema::OVERLAY_ELIGIBLE_FIELDS, true)) {
                continue;
            }

            // Null in overlay = explicit removal
            if ($overlayValue === null) {
                unset($result[$key]);
                continue;
            }

            // Both are arrays — decide merge strategy
            if (is_array($overlayValue) && isset($result[$key]) && is_array($result[$key])) {
                if (self::isSequentialArray($overlayValue)) {
                    // Sequential arrays (lists) are REPLACED entirely
                    // e.g., cookie_groups, script_blockers, content_blockers
                    $result[$key] = $overlayValue;
                } else {
                    // Associative arrays (objects) are deep-merged
                    // e.g., ui_config, translations, features, geo_rules
                    $result[$key] = self::deepMerge($result[$key], $overlayValue);
                }
            } else {
                // Scalar or type mismatch — overlay wins
                $result[$key] = $overlayValue;
            }
        }

        return $result;
    }

    /**
     * Deep merge two associative arrays.
     *
     * Overlay keys win. Sequential arrays within are replaced entirely.
     * Null values in overlay remove the base key.
     */
    public static function deepMerge(array $base, array $overlay): array
    {
        $result = $base;

        foreach ($overlay as $key => $overlayValue) {
            // Null in overlay = explicit removal
            if ($overlayValue === null) {
                unset($result[$key]);
                continue;
            }

            if (
                is_array($overlayValue)
                && isset($result[$key])
                && is_array($result[$key])
            ) {
                if (self::isSequentialArray($overlayValue)) {
                    $result[$key] = $overlayValue;
                } else {
                    $result[$key] = self::deepMerge($result[$key], $overlayValue);
                }
            } else {
                $result[$key] = $overlayValue;
            }
        }

        return $result;
    }

    /**
     * Check if an array is sequential (list) vs associative (object).
     *
     * An empty array is treated as sequential to avoid accidental deep-merge
     * of empty overlay arrays with populated base arrays.
     */
    public static function isSequentialArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }
        return array_is_list($arr);
    }
}
