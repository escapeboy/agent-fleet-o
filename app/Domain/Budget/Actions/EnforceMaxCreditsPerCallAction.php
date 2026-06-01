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

    /**
     * @param  int|null  $perRequestCap  Caller-specified ceiling for this single call.
     *                                   The effective cap is the lower of this and the
     *                                   team/agent standing cap; either alone applies if
     *                                   the other is null.
     */
    public function execute(
        string $teamId,
        string $provider,
        string $model,
        int $maxOutputTokens,
        int $estimatedInputTokens = 500,
        ?string $cacheStrategy = null,
        ?string $agentId = null,
        ?int $perRequestCap = null,
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
            $standingCap = $agent ? $agent->effectiveMaxCreditsPerCall($team) : $team->effectiveMaxCreditsPerCall();
        } else {
            $standingCap = $team->effectiveMaxCreditsPerCall();
        }

        $cap = $this->effectiveCap($standingCap, $perRequestCap);

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
                "Estimated {$estimate} platform_credits exceeds the per-call cap of {$cap}. ".
                'Raise max_credits_per_call (team/agent) or the request max-cost, or use a cheaper model.',
            );
        }
    }

    /**
     * The lower of the two caps; either alone when the other is null.
     */
    private function effectiveCap(?int $standingCap, ?int $perRequestCap): ?int
    {
        return match (true) {
            $standingCap === null => $perRequestCap,
            $perRequestCap === null => $standingCap,
            default => min($standingCap, $perRequestCap),
        };
    }
}
