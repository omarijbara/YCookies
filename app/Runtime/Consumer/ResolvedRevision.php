<?php

declare(strict_types=1);

namespace App\Runtime\Consumer;

/**
 * Value object for a resolved revision — everything needed to serve a request.
 */
final class ResolvedRevision
{
    public function __construct(
        public readonly array   $manifest,
        public readonly array   $baseArtifact,
        public readonly ?array  $routeIndex,
        public readonly array   $overlays, // overlay_id => decoded overlay array
        public readonly int     $revisionNumber,
        public readonly string  $schemaVersion,
        public readonly string  $manifestHash,
        public readonly string  $domainName,
        public readonly ?string $manifestSignature = null,
        public readonly ?string $publishedAt = null,
    ) {}

    /**
     * Get the overlay for a specific overlay_id.
     */
    public function getOverlay(string $overlayId): ?array
    {
        return $this->overlays[$overlayId] ?? null;
    }

    /**
     * Check if this revision has any overlays.
     */
    public function hasOverlays(): bool
    {
        return !empty($this->overlays);
    }
}
