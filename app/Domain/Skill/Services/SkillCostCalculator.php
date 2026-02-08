<?php

namespace App\Domain\Skill\Services;

use App\Domain\Budget\Services\CostCalculator;
use App\Domain\Skill\Models\Skill;

class SkillCostCalculator
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
    ) {}

    /**
     * Estimate the cost in credits for executing a skill.
     * Uses the skill's cost_profile if available, otherwise falls back
     * to the AI gateway's cost estimator.
     */
    public function estimate(Skill $skill, string $provider, string $model): int
    {
        $costProfile = $skill->cost_profile ?? [];

        // If skill has a fixed cost defined, use it
        if (isset($costProfile['fixed_cost_credits'])) {
            return (int) $costProfile['fixed_cost_credits'];
        }

        // Use estimated max tokens from skill config, or default
        $maxTokens = $costProfile['max_tokens'] ?? $skill->configuration['max_tokens'] ?? 4096;

        return $this->costCalculator->estimateCost(
            provider: $provider,
            model: $model,
            maxTokens: $maxTokens,
        );
    }

    /**
     * Calculate actual cost from a completed execution's usage data.
     */
    public function calculate(string $provider, string $model, int $inputTokens, int $outputTokens): int
    {
        return $this->costCalculator->calculateCost(
            provider: $provider,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }
}
