<?php

namespace Tests\Unit\Domain\Budget;

use App\Domain\Budget\Services\CostCalculator;
use Tests\TestCase;

/**
 * Claude Haiku 5.5 is $0.10 / $0.50 per MTok for prompts up to 100K tokens and
 * $0.50 / $2.50 above that (claude-api skill, shared/models.md, cached 2026-10-06).
 */
class LongContextPricingTest extends TestCase
{
    private function rawCost(int $input, int $output, int $cached = 0, ?int $cacheWrite = null): float
    {
        return (new CostCalculator)->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-haiku-5-5',
            inputTokens: $input,
            outputTokens: $output,
            cachedInputTokens: $cached,
            cacheWriteInputTokens: $cacheWrite,
        )['raw_cost_usd'];
    }

    public function test_prompt_at_the_threshold_uses_the_base_rate(): void
    {
        // 0.1M * $0.10 + 0.01M * $0.50
        $this->assertEqualsWithDelta(0.015, $this->rawCost(100_000, 10_000), 1e-9);
    }

    public function test_prompt_above_the_threshold_uses_the_long_context_rate(): void
    {
        // 0.15M * $0.50 + 0.01M * $2.50
        $this->assertEqualsWithDelta(0.10, $this->rawCost(150_000, 10_000), 1e-9);
    }

    public function test_accurate_mode_counts_cached_tokens_toward_the_threshold(): void
    {
        // 60K uncached + 50K cache reads = 110K prompt → long-context input/output.
        // Cache reads keep the base $0.01 rate: no long-context cache rate is published.
        // 0.06M * $0.50 + 0.05M * $0.01 + 0.01M * $2.50
        $this->assertEqualsWithDelta(0.0555, $this->rawCost(60_000, 10_000, 50_000, 0), 1e-9);
    }

    public function test_models_without_a_tier_are_unaffected(): void
    {
        $cost = (new CostCalculator)->calculatePlatformCredits('anthropic', 'claude-sonnet-5-5', 500_000, 0)['raw_cost_usd'];

        // 0.5M * $2.00
        $this->assertEqualsWithDelta(1.0, $cost, 1e-9);
    }
}
