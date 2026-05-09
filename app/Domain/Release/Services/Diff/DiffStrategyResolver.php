<?php

declare(strict_types=1);

namespace App\Domain\Release\Services\Diff;

/**
 * Picks a diff strategy based on the artifact's content type. Order of
 * resolution:
 *   1. Explicit content type from artifact metadata (`image/*`, `application/json`, etc.)
 *   2. Sniffed content type by leading-byte inspection
 *   3. Fallback to text strategy
 */
class DiffStrategyResolver
{
    public function __construct(
        private readonly TextLineDiff $text,
        private readonly JsonStructuralDiff $json,
        private readonly ImagePixelDiff $image,
    ) {}

    public function resolve(?string $contentType, ?string $sample = null): DiffStrategyInterface
    {
        $contentType = $contentType ?? $this->sniff($sample);

        if ($this->image->supports($contentType)) {
            return $this->image;
        }

        if ($this->json->supports($contentType)) {
            return $this->json;
        }

        return $this->text;
    }

    private function sniff(?string $sample): ?string
    {
        if ($sample === null || $sample === '') {
            return null;
        }

        // PNG magic
        if (str_starts_with($sample, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        // JPEG magic
        if (str_starts_with($sample, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }
        // WebP magic (RIFF....WEBP)
        if (str_starts_with($sample, 'RIFF') && substr($sample, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        // JSON
        $trimmed = ltrim($sample);
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return 'application/json';
        }

        return 'text/plain';
    }
}
