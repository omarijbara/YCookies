<?php

declare(strict_types=1);

namespace App\Runtime\Schema;

/**
 * Value object for the result of path normalization.
 */
final class NormalizedPath
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $locale = null,
    ) {}

    /**
     * Whether a locale was extracted from the path.
     */
    public function hasLocale(): bool
    {
        return $this->locale !== null;
    }

    public function toArray(): array
    {
        return [
            'path'   => $this->path,
            'locale' => $this->locale,
        ];
    }
}
