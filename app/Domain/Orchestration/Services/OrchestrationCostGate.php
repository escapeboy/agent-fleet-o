<?php

namespace App\Domain\Orchestration\Services;

use App\Domain\Orchestration\Exceptions\CostGateExceededException;
use App\Domain\Shared\Models\Team;

/**
 * Fan-out cost pre-flight gate. When enabled, a run whose projected cost
 * exceeds the threshold must be explicitly confirmed before it proceeds —
 * mirroring "show what's about to run and ask to confirm". A no-op when the
 * flag is off, so existing callers are unaffected.
 */
class OrchestrationCostGate
{
    public function enabled(): bool
    {
        return (bool) config('orchestration.cost_gate.enabled', false);
    }

    /**
     * Effective threshold in credits: a team override (settings) wins over the
     * config default.
     */
    public function thresholdFor(?Team $team): int
    {
        $override = $team?->settings['cost_gate_threshold_credits'] ?? null;

        if (is_numeric($override)) {
            return (int) $override;
        }

        return (int) config('orchestration.cost_gate.threshold_credits', 5000);
    }

    public function requiresConfirmation(int $projectedCredits, ?Team $team = null): bool
    {
        return $this->enabled() && $projectedCredits > $this->thresholdFor($team);
    }

    /**
     * @throws CostGateExceededException when the gate trips and the caller has
     *                                   not confirmed the spend.
     */
    public function assertConfirmed(int $projectedCredits, bool $confirmed, ?Team $team = null): void
    {
        if ($confirmed) {
            return;
        }

        if ($this->requiresConfirmation($projectedCredits, $team)) {
            throw new CostGateExceededException($projectedCredits, $this->thresholdFor($team));
        }
    }
}
