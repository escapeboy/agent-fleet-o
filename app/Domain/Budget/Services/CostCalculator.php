<?php

namespace App\Domain\Budget\Services;

class CostCalculator
{
    public function calculateCost(string $provider, string $model, int $inputTokens, int $outputTokens): int
    {
        $pricing = $this->getPricing($provider, $model);

        if (! $pricing) {
            return 0;
        }

        $inputCost = (int) ceil(($inputTokens / 1000) * $pricing['input']);
        $outputCost = (int) ceil(($outputTokens / 1000) * $pricing['output']);

        return $inputCost + $outputCost;
    }

    public function estimateCost(string $provider, string $model, int $maxTokens): int
    {
        $pricing = $this->getPricing($provider, $model);

        if (! $pricing) {
            return 0;
        }

        // Estimate: assume ~500 input tokens + full maxTokens output
        $estimatedInputTokens = 500;
        $inputCost = (int) ceil(($estimatedInputTokens / 1000) * $pricing['input']);
        $outputCost = (int) ceil(($maxTokens / 1000) * $pricing['output']);

        $multiplier = config('llm_pricing.reservation_multiplier', 1.5);

        return (int) ceil(($inputCost + $outputCost) * $multiplier);
    }

    /**
     * @return array{input: int, output: int}|null
     */
    private function getPricing(string $provider, string $model): ?array
    {
        return config("llm_pricing.providers.{$provider}.{$model}");
    }
}
