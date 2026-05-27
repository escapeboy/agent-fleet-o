<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\DTOs;

/**
 * A directed edge between two extracted elements, referenced by CodeGraph node ids.
 * `edgeType` is already normalized to FleetQ's set: calls | imports | inherits.
 */
final readonly class ExtractedEdge
{
    public function __construct(
        public string $sourceGraphId,
        public string $targetGraphId,
        public string $edgeType,
    ) {}
}
