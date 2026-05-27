<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\DTOs;

/**
 * A code element extracted from CodeGraph's SQLite index, normalized to FleetQ's
 * code_elements shape. `graphId` is CodeGraph's node id (e.g. "method:<hash>"),
 * retained only to resolve edges during ingestion.
 */
final readonly class ExtractedElement
{
    public function __construct(
        public string $graphId,
        public string $elementType,
        public string $name,
        public string $filePath,
        public ?int $lineStart,
        public ?int $lineEnd,
        public ?string $signature,
        public ?string $docstring,
        public string $language,
    ) {}
}
