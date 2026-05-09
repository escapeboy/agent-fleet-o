<?php

declare(strict_types=1);

namespace App\Domain\Release\Services\Diff;

use App\Domain\Release\Services\ArtifactVersionDiff;

/**
 * Adapter wrapping the existing Myers LCS line diff. Kept as a strategy so the
 * resolver can pick it for plain-text content types.
 */
class TextLineDiff implements DiffStrategyInterface
{
    public function __construct(private readonly ArtifactVersionDiff $inner) {}

    public function diff(?string $left, ?string $right, array $context = []): array
    {
        return $this->inner->diff($left, $right);
    }

    public function supports(?string $contentType): bool
    {
        return $contentType === null
            || str_starts_with($contentType, 'text/')
            || in_array($contentType, ['application/x-yaml', 'application/yaml', 'text/markdown'], true);
    }

    public function name(): string
    {
        return 'text';
    }
}
