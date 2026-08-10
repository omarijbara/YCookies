<?php

declare(strict_types=1);

namespace App\Runtime\Compiler;

/**
 * Value object for a compilation result.
 *
 * Contains all the artifacts and metadata produced by the compiler.
 * This is NOT yet persisted — it's passed to RevisionPublisher for
 * transactional storage and pointer movement.
 */
final class CompileResult
{
    public function __construct(
        public readonly array  $manifest,
        public readonly array  $baseArtifact,
        public readonly string $baseArtifactJson,
        public readonly string $baseArtifactHash,
        public readonly ?array $routeIndex,
        public readonly ?string $routeIndexJson,
        public readonly ?string $routeIndexHash,
        public readonly array  $overlays,          // [{overlay_id, overlay_json, overlay_hash, route_pattern}]
        public readonly string $manifestJson,
        public readonly string $manifestHash,
        public readonly string $compileInputsHash,
    ) {}
}
