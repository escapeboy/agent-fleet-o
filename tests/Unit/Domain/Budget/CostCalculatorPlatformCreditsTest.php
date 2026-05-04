<?php

namespace Tests\Unit\Domain\Budget;

use App\Domain\Budget\Services\CostCalculator;
use Tests\TestCase;

class CostCalculatorPlatformCreditsTest extends TestCase
{
    private CostCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new CostCalculator;
        // Defaults: usd_per_credit=0.01, margin=1.30, min=1
        config()->set('llm_pricing.usd_per_credit', 0.01);
        config()->set('llm_pricing.margin_multiplier', 1.30);
        config()->set('llm_pricing.min_credits_per_call', 1);
        config()->set('llm_pricing.max_credits_per_call', null);
    }

    public function test_nano_call_floors_at_min_credits(): void
    {
        $result = $this->calc->calculatePlatformCredits(
            provider: 'openai',
            model: 'gpt-5-nano',
            inputTokens: 3000,
            outputTokens: 800,
        );

        // raw = 3000/1e6 * 0.05 + 800/1e6 * 0.40 = 0.00015 + 0.00032 = 0.00047
        // billable = 0.00047 * 1.30 = 0.000611
        // ceil(0.000611 / 0.01) = 1, but min floor = 1
        $this->assertSame(1, $result['platform_credits']);
        $this->assertEqualsWithDelta(0.00047, $result['raw_cost_usd'], 0.0001);
        $this->assertEqualsWithDelta(0.000611, $result['billable_cost_usd'], 0.0001);
        $this->assertSame(1.30, $result['margin_applied']);
    }

    public function test_haiku_nano_call_floors_at_min(): void
    {
        $result = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            inputTokens: 3000,
            outputTokens: 800,
        );

        $this->assertSame(1, $result['platform_credits']);
        $this->assertGreaterThan(0, $result['raw_cost_usd']);
    }

    public function test_sonnet_medium_call(): void
    {
        $result = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            inputTokens: 5000,
            outputTokens: 2000,
        );

        // raw = 5000/1e6 * 3 + 2000/1e6 * 15 = 0.015 + 0.030 = 0.045
        // billable = 0.045 * 1.30 = 0.0585
        // ceil(0.0585 / 0.01) = 6
        $this->assertSame(6, $result['platform_credits']);
        $this->assertEqualsWithDelta(0.045, $result['raw_cost_usd'], 0.001);
    }

    public function test_opus_heavy_does_not_lose_money(): void
    {
        $result = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-opus-4-7',
            inputTokens: 10_000,
            outputTokens: 5000,
        );

        // raw = 10K/1e6 * 5 + 5K/1e6 * 25 = 0.05 + 0.125 = 0.175
        // billable = 0.175 * 1.30 = 0.2275
        // ceil(0.2275 / 0.01) = 23
        $this->assertGreaterThanOrEqual(23, $result['platform_credits']);
        // Customer pays 0.23+ USD; cost was 0.175 USD → ~30% margin. Not a loss.
        $charged = $result['platform_credits'] * 0.01;
        $this->assertGreaterThan($result['raw_cost_usd'], $charged, 'Charged amount must exceed raw cost.');
    }

    public function test_margin_override_takes_precedence(): void
    {
        $result = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-opus-4-7',
            inputTokens: 10_000,
            outputTokens: 5000,
            marginOverride: 1.50,
        );

        $this->assertSame(1.50, $result['margin_applied']);
        // Higher margin → more credits
        $resultDefault = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-opus-4-7',
            inputTokens: 10_000,
            outputTokens: 5000,
        );
        $this->assertGreaterThan($resultDefault['platform_credits'], $result['platform_credits']);
    }

    public function test_max_cap_clamps_runaway_call(): void
    {
        $result = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-opus-4-7',
            inputTokens: 100_000,
            outputTokens: 50_000,
            maxCapOverride: 10,
        );

        $this->assertSame(10, $result['platform_credits']);
    }

    public function test_min_floor_wins_over_max_cap(): void
    {
        $result = $this->calc->calculatePlatformCredits(
            provider: 'openai',
            model: 'gpt-5-nano',
            inputTokens: 100,
            outputTokens: 50,
            maxCapOverride: 20,
        );

        $this->assertSame(1, $result['platform_credits']);
    }

    public function test_unknown_model_returns_zero_gracefully(): void
    {
        $result = $this->calc->calculatePlatformCredits(
            provider: 'made-up-provider',
            model: 'made-up-model',
            inputTokens: 1000,
            outputTokens: 500,
        );

        $this->assertSame(0, $result['platform_credits']);
        $this->assertSame(0.0, $result['raw_cost_usd']);
    }

    public function test_cached_input_uses_cache_read_rate(): void
    {
        $with = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            inputTokens: 5000,
            outputTokens: 2000,
            cachedInputTokens: 4000,
        );
        $without = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            inputTokens: 5000,
            outputTokens: 2000,
            cachedInputTokens: 0,
        );

        // 4K cached at 0.30/Mtok vs 4K uncached at 3.00/Mtok → way cheaper
        $this->assertLessThan($without['raw_cost_usd'], $with['raw_cost_usd']);
        $this->assertLessThanOrEqual($without['platform_credits'], $with['platform_credits']);
    }

    public function test_cache_write_5m_adds_surcharge(): void
    {
        $with = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            inputTokens: 5000,
            outputTokens: 2000,
            cacheStrategy: CostCalculator::CACHE_STRATEGY_5M,
        );
        $without = $this->calc->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            inputTokens: 5000,
            outputTokens: 2000,
        );

        $this->assertGreaterThan($without['raw_cost_usd'], $with['raw_cost_usd']);
    }

    public function test_estimate_uses_tier_reservation_multiplier(): void
    {
        config()->set('llm_pricing.reservation_multipliers.nano', 1.2);
        config()->set('llm_pricing.reservation_multipliers.heavy', 2.0);

        $nano = $this->calc->estimatePlatformCredits('openai', 'gpt-5-nano', 500, 1000);
        $heavy = $this->calc->estimatePlatformCredits('anthropic', 'claude-opus-4-7', 500, 1000);

        // Nano: tier-multiplier 1.2 applies → smaller estimate buffer
        // Heavy: 2.0 applies → bigger buffer, plus heavy raw cost
        $this->assertGreaterThan($nano, $heavy);
    }

    public function test_back_compat_calculate_cost_still_returns_cost_credits(): void
    {
        $cost = $this->calc->calculateCost('anthropic', 'claude-sonnet-4-6', 5000, 2000);
        // raw = 0.045 USD; cost_credits = 0.045 / 0.001 = 45
        $this->assertSame(45, $cost);
    }
}
