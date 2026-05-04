<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Infrastructure\AI\Enums\BudgetPressureLevel;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CostCalculator
{
    public const CACHE_STRATEGY_NONE = 'none';

    public const CACHE_STRATEGY_5M = 'ephemeral_5m';

    public const CACHE_STRATEGY_1H = 'ephemeral_1h';

    /**
     * Back-compat: existing callers (BudgetEnforcement, PrismAiGateway, SkillCostCalculator)
     * read cost_credits where 1 credit = $0.001 USD.
     */
    public function calculateCost(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens = 0,
        ?string $cacheStrategy = null,
    ): int {
        $rawCostUsd = $this->rawCostUsd($provider, $model, $inputTokens, $outputTokens, $cachedInputTokens, $cacheStrategy);

        if ($rawCostUsd <= 0.0) {
            return 0;
        }

        $creditValueUsd = (float) config('llm_pricing.credit_value_usd', 0.001);

        return (int) ceil($rawCostUsd / $creditValueUsd);
    }

    /**
     * Back-compat: existing reservation flow uses cost_credits.
     */
    public function estimateCost(string $provider, string $model, int $maxTokens): int
    {
        $pricing = $this->getPricing($provider, $model);

        if ($pricing === null) {
            return 0;
        }

        $estimatedInputTokens = 500;
        $multiplier = $this->reservationMultiplierFor($pricing);

        $rawCostUsd = $this->rawCostUsd($provider, $model, $estimatedInputTokens, $maxTokens, 0, null);

        if ($rawCostUsd <= 0.0) {
            return 0;
        }

        $creditValueUsd = (float) config('llm_pricing.credit_value_usd', 0.001);

        return (int) ceil(($rawCostUsd / $creditValueUsd) * $multiplier);
    }

    /**
     * NEW — primary entry point for platform_credits deduction.
     *
     * @return array{
     *   platform_credits:int,
     *   raw_cost_usd:float,
     *   billable_cost_usd:float,
     *   margin_applied:float,
     *   model_pricing:array<string,mixed>|null
     * }
     */
    public function calculatePlatformCredits(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens = 0,
        ?string $cacheStrategy = null,
        ?float $marginOverride = null,
        ?int $maxCapOverride = null,
    ): array {
        $pricing = $this->getPricing($provider, $model);
        $rawCostUsd = $this->rawCostUsd($provider, $model, $inputTokens, $outputTokens, $cachedInputTokens, $cacheStrategy);

        $margin = $marginOverride ?? (float) config('llm_pricing.margin_multiplier', 1.30);
        $billableCostUsd = $rawCostUsd * $margin;

        $usdPerCredit = (float) config('llm_pricing.usd_per_credit', 0.01);
        $minCredits = (int) config('llm_pricing.min_credits_per_call', 1);
        $configMax = config('llm_pricing.max_credits_per_call');
        $maxCap = $maxCapOverride ?? ($configMax !== null ? (int) $configMax : null);

        if ($rawCostUsd <= 0.0) {
            return [
                'platform_credits' => 0,
                'raw_cost_usd' => 0.0,
                'billable_cost_usd' => 0.0,
                'margin_applied' => $margin,
                'model_pricing' => $pricing,
            ];
        }

        $rawCredits = (int) ceil($billableCostUsd / $usdPerCredit);
        $platformCredits = max($minCredits, $rawCredits);
        if ($maxCap !== null && $maxCap > 0) {
            $platformCredits = min($platformCredits, $maxCap);
            $platformCredits = max($minCredits, $platformCredits);
        }

        return [
            'platform_credits' => $platformCredits,
            'raw_cost_usd' => $rawCostUsd,
            'billable_cost_usd' => $billableCostUsd,
            'margin_applied' => $margin,
            'model_pricing' => $pricing,
        ];
    }

    /**
     * Pre-call platform-credit estimate for max-cap enforcement and reservation.
     * Uses tier-specific reservation multiplier.
     */
    public function estimatePlatformCredits(
        string $provider,
        string $model,
        int $estimatedInputTokens,
        int $maxOutputTokens,
        ?string $cacheStrategy = null,
        ?float $marginOverride = null,
    ): int {
        $pricing = $this->getPricing($provider, $model);

        if ($pricing === null) {
            return (int) config('llm_pricing.min_credits_per_call', 1);
        }

        $multiplier = $this->reservationMultiplierFor($pricing);

        $result = $this->calculatePlatformCredits(
            provider: $provider,
            model: $model,
            inputTokens: $estimatedInputTokens,
            outputTokens: $maxOutputTokens,
            cachedInputTokens: 0,
            cacheStrategy: $cacheStrategy,
            marginOverride: $marginOverride,
            maxCapOverride: null,
        );

        return (int) ceil($result['platform_credits'] * $multiplier);
    }

    public function getBudgetPressureLevel(string $teamId): BudgetPressureLevel
    {
        $enabled = GlobalSetting::get('ai_routing.budget_pressure_enabled') ?? config('ai_routing.budget_pressure.enabled', true);
        if (! $enabled) {
            return BudgetPressureLevel::None;
        }

        return Cache::remember(
            "budget_pressure:{$teamId}",
            60,
            fn () => $this->calculateBudgetPressure($teamId),
        );
    }

    private function calculateBudgetPressure(string $teamId): BudgetPressureLevel
    {
        $latestEntry = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['balance_after']);

        if (! $latestEntry) {
            return BudgetPressureLevel::None;
        }

        $totalBudget = (int) CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('type', [LedgerType::Purchase->value, LedgerType::Refund->value])
            ->sum('amount');

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

    private function rawCostUsd(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens,
        ?string $cacheStrategy,
    ): float {
        $pricing = $this->getPricing($provider, $model);

        if ($pricing === null) {
            Log::debug('cost_calculator.unknown_model', ['provider' => $provider, 'model' => $model]);

            return 0.0;
        }

        $inputRate = (float) ($pricing['input_usd_per_mtok'] ?? 0);
        $outputRate = (float) ($pricing['output_usd_per_mtok'] ?? 0);
        $cacheReadRate = (float) ($pricing['cache_read_usd_per_mtok'] ?? $inputRate);

        $cachedInputTokens = max(0, min($cachedInputTokens, $inputTokens));
        $uncachedInput = $inputTokens - $cachedInputTokens;

        $cost = ($uncachedInput / 1_000_000.0) * $inputRate
            + ($cachedInputTokens / 1_000_000.0) * $cacheReadRate
            + ($outputTokens / 1_000_000.0) * $outputRate;

        if ($cacheStrategy === self::CACHE_STRATEGY_5M
            && isset($pricing['cache_write_5m_usd_per_mtok'])) {
            $cost += ($inputTokens / 1_000_000.0) * (float) $pricing['cache_write_5m_usd_per_mtok'];
        } elseif ($cacheStrategy === self::CACHE_STRATEGY_1H
            && isset($pricing['cache_write_1h_usd_per_mtok'])) {
            $cost += ($inputTokens / 1_000_000.0) * (float) $pricing['cache_write_1h_usd_per_mtok'];
        }

        return $cost;
    }

    /**
     * @param  array<string,mixed>  $pricing
     */
    private function reservationMultiplierFor(array $pricing): float
    {
        $tier = (string) ($pricing['tier'] ?? 'default');
        $tiered = config("llm_pricing.reservation_multipliers.{$tier}");

        if ($tiered !== null) {
            return (float) $tiered;
        }

        return (float) config('llm_pricing.reservation_multiplier', 1.5);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getPricing(string $provider, string $model): ?array
    {
        $direct = config("llm_pricing.providers.{$provider}.{$model}");
        if ($direct !== null) {
            return $direct;
        }

        $wildcard = config("llm_pricing.providers.{$provider}.*");
        if ($wildcard !== null) {
            return $wildcard;
        }

        return null;
    }
}
