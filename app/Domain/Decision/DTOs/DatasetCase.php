<?php

namespace App\Domain\Decision\DTOs;

final readonly class DatasetCase
{
    /**
     * @param  array<string, mixed>|list<mixed>|string  $state
     * @param  array<string, array<string, mixed>>  $questions
     * @param  array<string, mixed>  $gold  question id => expected answer
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public array|string $state,
        public array $questions,
        public array $gold,
        public array $meta,
        public string $split,
    ) {}

    public function lang(): ?string
    {
        return is_string($this->meta['lang'] ?? null) ? $this->meta['lang'] : null;
    }

    public function variant(): ?string
    {
        return is_string($this->meta['variant'] ?? null) ? $this->meta['variant'] : null;
    }

    public function baseId(): ?string
    {
        return is_string($this->meta['base_id'] ?? null) ? $this->meta['base_id'] : null;
    }
}
