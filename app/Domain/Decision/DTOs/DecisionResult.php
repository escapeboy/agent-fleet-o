<?php

namespace App\Domain\Decision\DTOs;

/**
 * The result of one decide() call: one answer per question id, plus what the
 * eval harness needs to score it (which model answered, how many input tokens
 * it cost, how long the call took).
 */
final readonly class DecisionResult
{
    /**
     * @param  array<string, Answer>  $answers  keyed by the question ids the caller supplied
     */
    public function __construct(
        public array $answers,
        public string $model,
        public int $inputTokens,
        public int $latencyMs,
    ) {}

    public function answer(string $questionId): ?Answer
    {
        return $this->answers[$questionId] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'latency_ms' => $this->latencyMs,
            'answers' => array_map(static fn (Answer $a): array => $a->toArray(), $this->answers),
        ];
    }
}
