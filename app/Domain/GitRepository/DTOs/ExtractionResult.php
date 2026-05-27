<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\DTOs;

/**
 * Result of a polyglot extraction pass: normalized elements + edges ready for
 * IndexRepositoryAction to persist. Empty when the binary is absent or no
 * non-PHP source files were found.
 */
final readonly class ExtractionResult
{
    /**
     * @param  list<ExtractedElement>  $elements
     * @param  list<ExtractedEdge>  $edges
     */
    public function __construct(
        public array $elements = [],
        public array $edges = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->elements === [];
    }
}
