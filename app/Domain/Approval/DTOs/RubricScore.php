<?php

namespace App\Domain\Approval\DTOs;

/**
 * Immutable result of scoring an ActionProposal against the decision rubric.
 * Each dimension is 1-5 (5 = most favourable to auto-proceeding); `total` is
 * the sum out of 25. `recommendation` is the config-aware routing verdict:
 * `auto_execute`, `human_review`, or `auto_reject`.
 */
final class RubricScore
{
    public function __construct(
        public readonly int $impact,
        public readonly int $risk,
        public readonly int $cost,
        public readonly int $urgency,
        public readonly int $confidence,
        public readonly int $total,
        public readonly string $recommendation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'impact' => $this->impact,
            'risk' => $this->risk,
            'cost' => $this->cost,
            'urgency' => $this->urgency,
            'confidence' => $this->confidence,
            'total' => $this->total,
            'recommendation' => $this->recommendation,
        ];
    }
}
