<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Infrastructure\AI\Enums\BudgetPressureLevel;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;

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

    public function getBudgetPressureLevel(string $teamId): BudgetPressureLevel
    {
        $enabled = GlobalSetting::get('ai_routing.budget_pressure_enabled') ?? config('ai_routing.budget_pressure.enabled', true);
        if (! $enabled) {
            return BudgetPressureLevel::None;
        }

        return Cache::store('redis')->remember(
            "budget_pressure:{$teamId}",
            60,
            fn () => $this->calculateBudgetPressure($teamId),
        );
    }

    private function calculateBudgetPressure(string $teamId): BudgetPressureLevel
    {
        // Get team's latest balance from the most recent ledger entry
        $latestEntry = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['balance_after']);

        // No ledger entries at all — team has never had credits, no pressure
        if (! $latestEntry) {
            return BudgetPressureLevel::None;
        }

        // Get total purchased/refunded credits (the team's ceiling)
        $totalBudget = (int) CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('type', [LedgerType::Purchase->value, LedgerType::Refund->value])
            ->sum('amount');

        // No purchased credits — self-hosted/community install, no pressure
        if ($totalBudget <= 0) {
            return BudgetPressureLevel::None;
        }

        $spent = $totalBudget - $latestEntry->balance_after;
        $percentUsed = ($spent / $totalBudget) * 100;

        $thresholds = [
            'low' => (int) (GlobalSetting::get('ai_routing.budget_pressure_low') ?? config('ai_routing.budget_pressure.thresholds.low', 50)),
            'medium' => (int) (GlobalSetting::get('ai_routing.budget_pressure_medium') ?? config('ai_routing.budget_pressure.thresholds.medium', 75)),
            'high' => (int) (GlobalSetting::get('ai_routing.budget_pressure_high') ?? config('ai_routing.budget_pressure.thresholds.high', 90)),
        ];

        if ($percentUsed >= $thresholds['high']) {
            return BudgetPressureLevel::High;
        }

        if ($percentUsed >= $thresholds['medium']) {
            return BudgetPressureLevel::Medium;
        }

        if ($percentUsed >= $thresholds['low']) {
            return BudgetPressureLevel::Low;
        }

        return BudgetPressureLevel::None;
    }

    /**
     * @return array{input: int, output: int}|null
     */
    private function getPricing(string $provider, string $model): ?array
    {
        return config("llm_pricing.providers.{$provider}.{$model}");
    }
}
