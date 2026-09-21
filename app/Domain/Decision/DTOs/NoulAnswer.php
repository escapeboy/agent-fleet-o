<?php

namespace App\Domain\Decision\DTOs;

/**
 * A Noul answer: the probability that a yes/no statement is true, 0..1.
 *
 * The API returns no confidence for Noul — the value is the distribution.
 */
final readonly class NoulAnswer implements Answer
{
    public function __construct(
        public float $noul,
    ) {}

    public function type(): string
    {
        return 'noul';
    }

    public function value(): float
    {
        return $this->noul;
    }

    public function probabilities(): ?array
    {
        return null;
    }

    public function confidence(): ?float
    {
        return null;
    }

    public function toArray(): array
    {
        return [
            'type' => 'noul',
            'noul' => $this->noul,
        ];
    }
}
