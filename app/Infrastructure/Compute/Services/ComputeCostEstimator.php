<?php

namespace App\Infrastructure\Compute\Services;

/**
 * Estimates and calculates compute costs for analytics / reporting.
 *
 * Platform credits represent informational cost only — actual charges are
 * billed directly to the user's compute provider account (RunPod, etc.).
 * 1 credit = $0.001 USD.
 */
class ComputeCostEstimator
{
    /**
     * Estimate cost based on GPU type and planned runtime.
     */
    public function estimatePodCost(string $gpuTypeId, int $estimatedMinutes, bool $interruptible = false): int
    {
        $creditsPerHour = $this->resolveCreditsPerHour($gpuTypeId, $interruptible);
        $durationHours = $estimatedMinutes / 60;

        return (int) round($creditsPerHour * $durationHours);
    }

    /**
     * Calculate actual cost based on measured execution duration.
     */
    public function calculateActualCost(string $gpuTypeId, int $durationMs, bool $interruptible = false): int
    {
        $creditsPerHour = $this->resolveCreditsPerHour($gpuTypeId, $interruptible);
        $durationHours = $durationMs / 3_600_000;

        return (int) round($creditsPerHour * $durationHours);
    }

    private function resolveCreditsPerHour(string $gpuTypeId, bool $interruptible): int
    {
        $gpuPrices = config('compute_providers.gpu_credits_per_hour', []);
        $creditsPerHour = $gpuPrices[$gpuTypeId] ?? $gpuPrices['default'] ?? 500;

        if ($interruptible) {
            $spotDiscount = (float) config('compute_providers.spot_discount', 0.4);
            $creditsPerHour = (int) ($creditsPerHour * $spotDiscount);
        }

        return $creditsPerHour;
    }
}
