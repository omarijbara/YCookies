<?php

declare(strict_types=1);

namespace App\Runtime\Schema;

/**
 * ManifestSchema — Canonical Runtime Contract
 *
 * This class defines the immutable shape of published runtime artifacts.
 * Every consumer (proxy, SDK, compatibility shim) depends on this contract.
 *
 * IMPORTANT: Changes to this schema MUST be versioned. Additive (minor)
 * changes are backward-compatible. Structural (major) changes require
 * a compatibility gate that blocks publish until consumers are updated.
 *
 * Schema versioning:
 *   - MAJOR: breaking structural changes (fields removed, types changed)
 *   - MINOR: additive changes (new optional fields)
 *   - PATCH: documentation/comment-only changes
 */
final class ManifestSchema
{
    public const SCHEMA_VERSION = '1.0.0';
    public const SCHEMA_MAJOR = 1;
    public const SCHEMA_MINOR = 0;
    public const SCHEMA_PATCH = 0;

    // ─── Artifact types ─────────────────────────────────────────────
    public const ARTIFACT_BASE = 'base';
    public const ARTIFACT_ROUTE_INDEX = 'route_index';
    public const ARTIFACT_OVERLAY = 'overlay';

    // ─── Revision statuses ──────────────────────────────────────────
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_FAILED,
        self::STATUS_ROLLED_BACK,
    ];

    // ─── Route match types (precedence order, highest first) ────────
    public const MATCH_EXACT = 'exact';           // /about
    public const MATCH_WILDCARD = 'wildcard';      // /blog/*
    public const MATCH_GLOBSTAR = 'globstar';      // /docs/**
    public const MATCH_DEFAULT = 'default';        // fallback (base only)

    public const MATCH_PRECEDENCE = [
        self::MATCH_EXACT    => 4,
        self::MATCH_WILDCARD => 3,
        self::MATCH_GLOBSTAR => 2,
        self::MATCH_DEFAULT  => 1,
    ];

    // ─── Fields forbidden from runtime artifacts ────────────────────
    // These are request-derived and must NEVER be baked into immutable artifacts.
    public const FORBIDDEN_IN_ARTIFACTS = [
        'raw_ip',
        'raw_headers',
        'raw_query_params',
        'visitor_country',   // request-derived, not compilation input
        'live_geo_results',
        'per_user_data',
        'cf_ipcountry',
    ];

    // ─── Fields that are base-only (never in overlays) ──────────────
    public const BASE_ONLY_FIELDS = [
        'site_id',
        'domain',
        'cross_domain_enabled',
        'cross_domains_list',
        'tcf_config',
        'callbacks',
        'consent_version',
    ];

    // ─── Fields eligible for overlay override ───────────────────────
    public const OVERLAY_ELIGIBLE_FIELDS = [
        'cookie_groups',
        'content_blockers',
        'script_blockers',
        'style_blockers',
        'auto_blocking',
        'ui_config',
        'translations',
        'geo_rules',
        'features',
    ];

    /**
     * Build a manifest envelope structure.
     *
     * The manifest is the signed top-level document that references
     * all sub-artifacts by hash. Consumers verify the manifest signature
     * first, then verify each artifact hash against the manifest.
     *
     * @param array $params Manifest parameters
     * @return array The manifest envelope
     */
    public static function buildManifestEnvelope(array $params): array
    {
        return [
            'schema_version'  => self::SCHEMA_VERSION,
            'domain'          => $params['domain'],
            'site_id'         => $params['site_id'],
            'revision'        => $params['revision'],
            'issued_at'       => $params['issued_at'],
            'artifacts'       => $params['artifacts'],  // ['base' => {hash, size}, 'route_index' => {hash, size}, 'overlays' => [{id, hash, size}]]
            'signature'       => $params['signature'] ?? null,
        ];
    }

    /**
     * Build the canonical base artifact structure.
     *
     * This is the primary runtime contract consumed by proxy and SDK.
     * It contains everything needed to enforce consent policy for a domain
     * when no route-specific overlay applies.
     */
    public static function buildBaseArtifact(array $params): array
    {
        return [
            // ── Identity ────────────────────────────────────────────
            'site_id'              => $params['site_id'],
            'domain'               => $params['domain'],
            'consent_version'      => $params['consent_version'] ?? 1,
            'cross_domain_enabled' => $params['cross_domain_enabled'] ?? false,
            'cross_domains_list'   => $params['cross_domains_list'] ?? [],

            // ── UI / Banner ─────────────────────────────────────────
            'ui_config'    => $params['ui_config'] ?? [],
            'translations' => $params['translations'] ?? [],
            'localization' => $params['localization'] ?? [],
            'languages'    => $params['languages'] ?? [],

            // ── Consent Groups & Services ───────────────────────────
            'cookie_groups' => $params['cookie_groups'] ?? [],

            // ── Blocking Rules ──────────────────────────────────────
            'script_blockers'  => $params['script_blockers'] ?? [],
            'content_blockers' => $params['content_blockers'] ?? [],
            'style_blockers'   => $params['style_blockers'] ?? [],
            'auto_blocking'    => $params['auto_blocking'] ?? [
                'content' => true,
                'script' => true,
                'style' => true,
                'service' => true,
            ],

            // ── Cookie Policy (proxy enforcement) ───────────────────
            'cookie_policy' => $params['cookie_policy'] ?? ['mode' => 'passthrough'],

            // ── Google Consent Mode / TCF ────────────────────────────
            'tcm_config' => $params['tcm_config'] ?? ['enabled' => false],
            'tcf_config' => $params['tcf_config'] ?? ['enabled' => false],

            // ── Geo / Compliance ─────────────────────────────────────
            'geo_rules'          => $params['geo_rules'] ?? [],
            'geo_restriction_eu' => $params['geo_restriction_eu'] ?? false,
            'geo_skipped_countries' => $params['geo_skipped_countries'] ?? [],

            // ── Proxy-specific ───────────────────────────────────────
            'origin' => $params['origin'] ?? null,
            'proxy'  => $params['proxy'] ?? null,

            // ── Bootstrapper / Delivery ──────────────────────────────
            'bootstrapper' => $params['bootstrapper'] ?? null,

            // ── Feature Flags ────────────────────────────────────────
            'features' => $params['features'] ?? [],

            // ── Callbacks ────────────────────────────────────────────
            'callbacks' => $params['callbacks'] ?? [
                'onReady'         => 'window.ycookiesDispatchEvent("ready")',
                'onConsentUpdate' => 'window.ycookiesDispatchEvent("consent_update")',
            ],
        ];
    }

    /**
     * Build a route index entry.
     *
     * Route index maps URL patterns to overlay IDs.
     * Resolution order: exact > longest wildcard > longest globstar > priority > lexical.
     */
    public static function buildRouteEntry(array $params): array
    {
        return [
            'pattern'    => $params['pattern'],      // e.g., '/blog/*', '/about', '/docs/**'
            'overlay_id' => $params['overlay_id'],   // references runtime_overlays.overlay_id
            'match_type' => $params['match_type'],   // exact, wildcard, globstar
            'priority'   => $params['priority'] ?? 0,
        ];
    }

    /**
     * Build a route index artifact (array of route entries).
     */
    public static function buildRouteIndex(array $entries): array
    {
        return [
            'routes' => $entries,
        ];
    }

    /**
     * Build an overlay artifact.
     *
     * Overlays are sparse — they contain only the fields that differ
     * from the base artifact. Resolution is: base + zero/one overlay.
     * No chaining. Array fields are replaced entirely, not merged.
     */
    public static function buildOverlay(array $params): array
    {
        $overlay = [
            'overlay_id' => $params['overlay_id'],
        ];

        // Only include fields that are actually overridden
        foreach (self::OVERLAY_ELIGIBLE_FIELDS as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== null) {
                $overlay[$field] = $params[$field];
            }
        }

        return $overlay;
    }

    /**
     * Build the artifact reference map for the manifest.
     *
     * Each artifact is referenced by its SHA-256 hash and byte size.
     * Consumers verify fetched artifact content against these hashes.
     */
    public static function buildArtifactRef(string $hash, int $size): array
    {
        return [
            'hash'      => $hash,
            'size'      => $size,
            'algorithm' => 'sha256',
        ];
    }

    /**
     * Hash an artifact's canonical JSON representation.
     *
     * Canonical JSON: sorted keys, no pretty-print, no trailing newline.
     * This ensures cross-language hash agreement.
     */
    public static function hashArtifact(array $artifact): string
    {
        $canonical = self::canonicalize($artifact);
        return hash('sha256', $canonical);
    }

    /**
     * Produce canonical JSON for signing and hashing.
     *
     * Rules:
     *   1. Keys sorted recursively
     *   2. No pretty-print (compact)
     *   3. No trailing newline
     *   4. Unicode unescaped
     *   5. Slashes unescaped
     *
     * Both PHP and Node implementations MUST produce identical output
     * for the same input. This is verified by cross-language tests.
     */
    public static function canonicalize(array $data): string
    {
        $sorted = self::sortKeysRecursive($data);
        return json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Recursively sort array keys for deterministic serialization.
     * Sequential (numeric-keyed) arrays are left in order.
     */
    public static function sortKeysRecursive(array $data): array
    {
        // Check if this is a sequential (list) array — don't sort these
        if (array_is_list($data)) {
            return array_map(function ($value) {
                return is_array($value) ? self::sortKeysRecursive($value) : $value;
            }, $data);
        }

        // Associative array — sort by key
        ksort($data, SORT_STRING);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeysRecursive($value);
            }
        }

        return $data;
    }

    /**
     * Validate that an artifact does not contain forbidden fields.
     *
     * @return string[] List of violations (empty = valid)
     */
    public static function validateNoForbiddenFields(array $artifact, string $prefix = ''): array
    {
        $violations = [];
        foreach ($artifact as $key => $value) {
            $path = $prefix ? "{$prefix}.{$key}" : $key;
            if (in_array($key, self::FORBIDDEN_IN_ARTIFACTS, true)) {
                $violations[] = "Forbidden field in artifact: {$path}";
            }
            if (is_array($value)) {
                $violations = array_merge(
                    $violations,
                    self::validateNoForbiddenFields($value, $path)
                );
            }
        }
        return $violations;
    }

    /**
     * Validate that an overlay only contains overlay-eligible fields
     * (plus the required overlay_id).
     *
     * @return string[] List of violations (empty = valid)
     */
    public static function validateOverlayFields(array $overlay): array
    {
        $violations = [];
        $allowed = array_merge(self::OVERLAY_ELIGIBLE_FIELDS, ['overlay_id']);

        foreach (array_keys($overlay) as $key) {
            if (!in_array($key, $allowed, true)) {
                if (in_array($key, self::BASE_ONLY_FIELDS, true)) {
                    $violations[] = "Base-only field '{$key}' cannot appear in overlay";
                } else {
                    $violations[] = "Unknown field '{$key}' in overlay";
                }
            }
        }

        return $violations;
    }

    /**
     * Validate a complete manifest envelope structure.
     *
     * @return string[] List of violations (empty = valid)
     */
    public static function validateManifest(array $manifest): array
    {
        $violations = [];

        $required = ['schema_version', 'domain', 'site_id', 'revision', 'issued_at', 'artifacts'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $manifest)) {
                $violations[] = "Missing required manifest field: {$field}";
            }
        }

        if (isset($manifest['schema_version'])) {
            $parts = explode('.', $manifest['schema_version']);
            if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
                $violations[] = "Invalid schema_version format: {$manifest['schema_version']}";
            }
        }

        if (isset($manifest['artifacts'])) {
            if (!isset($manifest['artifacts']['base'])) {
                $violations[] = "Manifest must contain a 'base' artifact reference";
            }
            // Validate each artifact ref has hash + size
            foreach ($manifest['artifacts'] as $name => $ref) {
                if ($name === 'overlays') {
                    if (!is_array($ref)) {
                        $violations[] = "Artifacts.overlays must be an array";
                    } else {
                        foreach ($ref as $i => $overlayRef) {
                            if (!isset($overlayRef['id'])) {
                                $violations[] = "Overlay ref [{$i}] missing 'id'";
                            }
                            if (!isset($overlayRef['hash'])) {
                                $violations[] = "Overlay ref [{$i}] missing 'hash'";
                            }
                        }
                    }
                    continue;
                }
                if (!isset($ref['hash'])) {
                    $violations[] = "Artifact '{$name}' missing 'hash'";
                }
                if (!isset($ref['size'])) {
                    $violations[] = "Artifact '{$name}' missing 'size'";
                }
            }
        }

        return $violations;
    }
}
