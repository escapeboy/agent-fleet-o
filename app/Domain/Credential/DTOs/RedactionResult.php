<?php

namespace App\Domain\Credential\DTOs;

final class RedactionResult
{
    /**
     * @param  array<string, int>  $counts  Replacements per layer.
     */
    public function __construct(
        public readonly string $text,
        public readonly array $counts = [],
    ) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
