<?php

namespace App\Domain\Budget\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Budget\Services\CostCalculator;
use App\Domain\Shared\Models\Team;

/**
 * Pre-call enforcement of the per-team max-platform-credits-per-call cap.
 *
 * Throws InsufficientBudgetException BEFORE the LLM call so a runaway Opus call
 * cannot single-handedly drain the customer's monthly credit pool.
 *
 * Skipped when team has no cap (cap = null).
 */
class EnforceMaxCreditsPerCallAction
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
    ) {}

    public function execute(
        string $teamId,
        string $provider,
        string $model,
        int $maxOutputTokens,
        int $estimatedInputTokens = 500,
        ?string $cacheStrategy = null,
        ?string $agentId = null,
    ): void {
        if ($teamId === '') {
            return;
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            return;
        }

        if ($agentId !== null) {
            $agent = Agent::withoutGlobalScopes()->find($agentId);
            $cap = $agent ? $agent->effectiveMaxCreditsPerCall($team) : $team->effectiveMaxCreditsPerCall();
        } else {
            $cap = $team->effectiveMaxCreditsPerCall();
        }

        if ($cap === null) {
            return;
        }

        $marginOverride = $team->effectiveMarginMultiplier();

        $estimate = $this->costCalculator->estimatePlatformCredits(
            provider: $provider,
            model: $model,
            estimatedInputTokens: $estimatedInputTokens,
            maxOutputTokens: $maxOutputTokens,
            cacheStrategy: $cacheStrategy,
            marginOverride: $marginOverride,
        );

        if ($estimate > $cap) {
            throw new InsufficientBudgetException(
                "Estimated {$estimate} platform_credits exceeds team cap of {$cap}. ".
                'Increase max_credits_per_call in team settings or use a cheaper model.',
            );
        }
    }
}
