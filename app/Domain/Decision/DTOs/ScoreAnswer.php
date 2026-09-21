<?php

namespace App\Domain\Decision\DTOs;

/**
 * A Score answer: a probability-weighted value across the ordered levels the
 * caller defined. `score` can land between levels; `legend` maps each level
 * index back to its description.
 */
final readonly class ScoreAnswer implements Answer
{
    /**
     * @param  array<string, float>|null  $probabilities
     * @param  array<string, string>|null  $legend
     */
    public function __construct(
        public float $score,
        public ?array $probabilities = null,
        public ?float $confidence = null,
        public ?array $legend = null,
    ) {}

    public function type(): string
    {
        return 'score';
    }

    public function value(): float
    {
        return $this->score;
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
            'type' => 'score',
            'score' => $this->score,
            'probabilities' => $this->probabilities,
            'confidence' => $this->confidence,
            'legend' => $this->legend,
        ];
    }
}
