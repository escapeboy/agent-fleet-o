<?php

namespace App\Domain\Orchestration\Exceptions;

use RuntimeException;

/**
 * Thrown when an orchestration run's estimated cost exceeds the configured
 * pre-flight threshold and the caller has not explicitly confirmed the spend.
 * Carries the figures so callers (API/MCP/UI) can show "≈ X credits, confirm?".
 */
class CostGateExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $projectedCredits,
        public readonly int $thresholdCredits,
    ) {
        parent::__construct(
            "Estimated {$projectedCredits} credits exceeds the cost-gate threshold of {$thresholdCredits}. ".
            'Re-run with confirmation to proceed.',
        );
    }
}
