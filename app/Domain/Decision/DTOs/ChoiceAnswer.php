<?php

namespace App\Domain\Decision\DTOs;

/**
 * A Choice answer: one option picked from a caller-defined set.
 *
 * `probabilities` maps every option to its probability and sums to 1 for the
 * System One driver. LLM-backed drivers may not expose a distribution at all,
 * in which case it stays null and `confidence` is null too.
 */
final readonly class ChoiceAnswer implements Answer
{
    /**
     * @param  array<string, float>|null  $probabilities
     */
    public function __construct(
        public string $choice,
        public ?array $probabilities = null,
        public ?float $confidence = null,
    ) {}

    public function type(): string
    {
        return 'choice';
    }

    public function value(): string
    {
        return $this->choice;
    }

    public function probabilities(): ?array
    {
        return $this->probabilities;
    }

    public function confidence(): ?float
    {
        return $this->confidence;
    }

    public function toArray(): array
    {
        return [
            'type' => 'choice',
            'choice' => $this->choice,
            'probabilities' => $this->probabilities,
            'confidence' => $this->confidence,
        ];
    }
}
