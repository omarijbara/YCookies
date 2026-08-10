<?php

declare(strict_types=1);

namespace App\Runtime\Consumer;

use App\Runtime\Schema\PathNormalizer;
use App\Runtime\Schema\OverlayMerger;

/**
 * EffectivePolicyBuilder — Merges base + overlay for a specific request path.
 *
 * Given a resolved revision and a URL path, returns the effective runtime
 * config that consumers should use for enforcement.
 */
class EffectivePolicyBuilder
{
    /**
     * Build the effective runtime config for a request.
     *
     * @param ResolvedRevision $revision       The active revision
     * @param string           $requestPath    The incoming request path
     * @param array            $localePrefixes Optional locale prefixes for path normalization
     * @return array The effective runtime config (base merged with applicable overlay)
     */
    public function build(
        ResolvedRevision $revision,
        string $requestPath = '/',
        array $localePrefixes = [],
    ): array {
        $base = $revision->baseArtifact;

        // If no route index, return base-only
        $routeIndex = $revision->routeIndex;
        if (!$routeIndex || !$revision->hasOverlays()) {
            return $base;
        }

        // Normalize path
        $normalized = PathNormalizer::normalize($requestPath, $localePrefixes);

        // Match against route index
        $match = PathNormalizer::matchRoute($normalized->path, $routeIndex);
        if (!$match) {
            return $base;
        }

        // Get the matching overlay
        $overlay = $revision->getOverlay($match->overlayId);
        if (!$overlay) {
            return $base;
        }

        // Merge base + overlay
        return OverlayMerger::merge($base, $overlay);
    }
}
