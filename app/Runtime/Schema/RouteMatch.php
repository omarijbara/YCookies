<?php

declare(strict_types=1);

namespace App\Runtime\Schema;

/**
 * Value object for the result of route matching.
 */
final class RouteMatch
{
    public function __construct(
        public readonly string $overlayId,
        public readonly string $matchType,
        public readonly string $matchedPattern,
    ) {}

    public function toArray(): array
    {
        return [
            'overlay_id'      => $this->overlayId,
            'match_type'      => $this->matchType,
            'matched_pattern' => $this->matchedPattern,
        ];
    }
}
