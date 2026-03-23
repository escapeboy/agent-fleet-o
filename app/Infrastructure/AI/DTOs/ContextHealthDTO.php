<?php

namespace App\Infrastructure\AI\DTOs;

/**
 * Snapshot of an experiment's LLM context health.
 * Computed by ContextHealthService from llm_request_logs.
 */
final readonly class ContextHealthDTO
{
    public function __construct(
        public string $experimentId,
        /** Total input tokens consumed by this experiment across all stages. */
        public int $totalInputTokens,
        /** Context window size of the primary model used (tokens). */
        public int $contextWindowTokens,
        /** Fraction of context window used, 0.0–1.0. */
        public float $contextUsedFraction,
        /** Whether this experiment is approaching the context limit (>= warning threshold). */
        public bool $isApproachingLimit,
        /** Whether this experiment has exceeded the critical context threshold. */
        public bool $isCritical,
        /** The model that was resolved as primary for this experiment. */
        public string $primaryModel,
    ) {}

    public function contextUsedPercent(): float
    {
        return round($this->contextUsedFraction * 100, 1);
    }

    public function level(): string
    {
        if ($this->isCritical) {
            return 'critical';
        }

        if ($this->isApproachingLimit) {
            return 'warning';
        }

        return 'healthy';
    }
}
