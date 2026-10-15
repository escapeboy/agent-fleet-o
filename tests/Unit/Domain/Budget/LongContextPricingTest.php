<?php

namespace Tests\Unit\Domain\Budget;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * Claude Haiku 5.5 per MTok (platform.claude.com/docs/en/about-claude/pricing.md, 2026-10-15):
 *   prompts up to 100K: input $0.10, 5m write $0.125, 1h write $0.20, cache hit $0.01, output $0.50
 *   prompts over 100K:  input $0.50, 5m write $0.625, 1h write $1.00, cache hit $0.05, output $2.50
 * "A request's prompt length counts all of its input tokens, including cache reads and cache writes."
 */
class LongContextPricingTest extends TestCase
{
    private function rawCost(int $input, int $output, int $cached = 0, ?int $cacheWrite = null, ?string $strategy = null, ?int $promptTokens = null): float
    {
        $args = [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'inputTokens' => $input,
            'outputTokens' => $output,
            'cachedInputTokens' => $cached,
            'cacheStrategy' => $strategy,
            'cacheWriteInputTokens' => $cacheWrite,
        ];
        if ($promptTokens !== null) {
            $args['promptTokens'] = $promptTokens;
        }

        return (new CostCalculator)->calculatePlatformCredits(...$args)['raw_cost_usd'];
    }

    private function gatewayUsage(Usage $usage): AiUsageDTO
    {
        $request = new AiRequestDTO(provider: 'anthropic', model: 'claude-haiku-5-5', systemPrompt: 'sys', userPrompt: 'user', enablePromptCaching: false);
        $method = (new \ReflectionClass(PrismAiGateway::class))->getMethod('buildUsageDTO');

        return $method->invoke(new PrismAiGateway(new CostCalculator), $usage, $request);
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

    public function test_accurate_mode_cache_reads_cross_the_threshold_and_use_the_long_context_read_rate(): void
    {
        // 5K + 150K read = 155K prompt. 0.005M * $0.50 + 0.15M * $0.05 + 0.001M * $2.50
        $this->assertEqualsWithDelta(0.0125, $this->rawCost(5_000, 1_000, 150_000, 0), 1e-9);
    }

    public function test_accurate_mode_cache_writes_cross_the_threshold_and_use_the_long_context_write_rate(): void
    {
        // 5K + 150K write = 155K prompt. 0.005M * $0.50 + 0.15M * $0.625 + 0.001M * $2.50
        $this->assertEqualsWithDelta(0.09875, $this->rawCost(5_000, 1_000, 0, 150_000), 1e-9);
    }

    public function test_accurate_mode_below_the_threshold_keeps_base_cache_rates(): void
    {
        // 5K + 90K read = 95K prompt. 0.005M * $0.10 + 0.09M * $0.01 + 0.001M * $0.50
        $this->assertEqualsWithDelta(0.0019, $this->rawCost(5_000, 1_000, 90_000, 0), 1e-9);
    }

    public function test_legacy_mode_counts_anthropic_cache_reads_toward_the_threshold(): void
    {
        // Anthropic input_tokens exclude cache reads: 5K + 150K = 155K prompt → long tier.
        // The legacy formula clamps reads to input (pinned in AccurateUsageCostTest), so only
        // the rates change here: 0.005M * $0.05 + 0.001M * $2.50
        $this->assertEqualsWithDelta(0.00275, $this->rawCost(5_000, 1_000, 150_000), 1e-9);
    }

    public function test_legacy_mode_below_the_threshold_keeps_base_rates(): void
    {
        // 5K + 90K = 95K prompt. 0.005M * $0.01 + 0.001M * $0.50
        $this->assertEqualsWithDelta(0.00055, $this->rawCost(5_000, 1_000, 90_000), 1e-9);
    }

    public function test_legacy_mode_uses_the_caller_prompt_length_for_cache_writes(): void
    {
        // Legacy has no cache-write bucket; the caller passes the full prompt length (155K).
        // 0.005M * $0.50 + 0.001M * $2.50 + 0.005M * $0.625 (legacy write surcharge on input)
        $this->assertEqualsWithDelta(0.008125, $this->rawCost(5_000, 1_000, 0, null, CostCalculator::CACHE_STRATEGY_5M, 155_000), 1e-9);
    }

    public function test_gateway_legacy_mode_picks_the_tier_from_reads_and_writes(): void
    {
        config(['llm_pricing.accurate_usage_cost' => false]);

        // 155K via cache reads: legacy $0.00275 → 3 credits (base tier would be 1); accurate $0.0125 → 13.
        $reads = $this->gatewayUsage(new Usage(5_000, 1_000, cacheReadInputTokens: 150_000));
        $this->assertSame(3, $reads->costCredits);
        $this->assertSame(13, $reads->accurateCostCredits);

        // 155K via cache writes: legacy 0.005M * $0.50 + 0.001M * $2.50 = $0.005 → 5 credits
        // (base tier would be 1); accurate $0.09875 → 99.
        $writes = $this->gatewayUsage(new Usage(5_000, 1_000, cacheWriteInputTokens: 150_000));
        $this->assertSame(5, $writes->costCredits);
        $this->assertSame(99, $writes->accurateCostCredits);

        // 95K: base tier in both formulas. Legacy $0.00055 → 1; accurate $0.0019 → 2.
        $control = $this->gatewayUsage(new Usage(5_000, 1_000, cacheReadInputTokens: 90_000));
        $this->assertSame(1, $control->costCredits);
        $this->assertSame(2, $control->accurateCostCredits);
    }

    public function test_models_without_a_tier_are_unaffected(): void
    {
        $cost = (new CostCalculator)->calculatePlatformCredits('anthropic', 'claude-sonnet-5-5', 500_000, 0)['raw_cost_usd'];

        // 0.5M * $2.00
        $this->assertEqualsWithDelta(1.0, $cost, 1e-9);
    }
}
