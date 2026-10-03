<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Services\UsageNormalizer;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * Prism usage → non-overlapping billable buckets → cost. Expected values are
 * computed by hand from config/llm_pricing.php.
 */
class AccurateUsageCostTest extends TestCase
{
    private function buildUsage(string $provider, string $model, Usage $usage, bool $caching = true): AiUsageDTO
    {
        $request = new AiRequestDTO(provider: $provider, model: $model, systemPrompt: 'sys', userPrompt: 'user', enablePromptCaching: $caching);
        $method = (new \ReflectionClass(PrismAiGateway::class))->getMethod('buildUsageDTO');

        return $method->invoke(new PrismAiGateway(new CostCalculator), $usage, $request);
    }

    public function test_normalizer_buckets_per_provider(): void
    {
        $anthropic = UsageNormalizer::fromPrism('anthropic', new Usage(200, 300, cacheWriteInputTokens: 1000, cacheReadInputTokens: 50000));
        $this->assertSame([200, 50000, 1000, 300], [$anthropic->uncachedInputTokens, $anthropic->cacheReadInputTokens, $anthropic->cacheWriteInputTokens, $anthropic->outputTokens]);

        // Prism already subtracted cached tokens from OpenAI input; reasoning is inside output.
        $openai = UsageNormalizer::fromPrism('openai', new Usage(800, 400, cacheReadInputTokens: 1200, thoughtTokens: 300));
        $this->assertSame([800, 1200, 0, 400], [$openai->uncachedInputTokens, $openai->cacheReadInputTokens, $openai->cacheWriteInputTokens, $openai->outputTokens]);

        // Gemini input includes implicit cache hits; thinking tokens are billed as output.
        $gemini = UsageNormalizer::fromPrism('google', new Usage(1000, 500, cacheReadInputTokens: 400, thoughtTokens: 2000));
        $this->assertSame([600, 400, 0, 2500], [$gemini->uncachedInputTokens, $gemini->cacheReadInputTokens, $gemini->cacheWriteInputTokens, $gemini->outputTokens]);
    }

    public function test_accurate_formula_prices_each_bucket(): void
    {
        // 200·3.00 + 50 000·0.30 + 1 000·3.75 + 300·15.00 per Mtok = 0.02385 USD → 24 credits
        $credits = (new CostCalculator)->calculateCost('anthropic', 'claude-sonnet-4-6', 200, 300, 50000, cacheWriteInputTokens: 1000);

        $this->assertSame(24, $credits);
    }

    public function test_legacy_formula_unchanged_without_cache_write_param(): void
    {
        // Legacy: cache reads clamped to input (200), write surcharge on all input.
        $credits = (new CostCalculator)->calculateCost('anthropic', 'claude-sonnet-4-6', 200, 300, 50000, CostCalculator::CACHE_STRATEGY_5M);

        $this->assertSame(6, $credits);
    }

    public function test_flag_off_charges_legacy_and_records_accurate(): void
    {
        config(['llm_pricing.accurate_usage_cost' => false]);

        $usage = $this->buildUsage('anthropic', 'claude-sonnet-4-6', new Usage(200, 300, cacheWriteInputTokens: 1000, cacheReadInputTokens: 50000));

        $this->assertSame(6, $usage->costCredits);
        $this->assertSame(24, $usage->accurateCostCredits);
        $this->assertSame(1000, $usage->cacheWriteInputTokens);
    }

    public function test_flag_on_charges_accurate_cost(): void
    {
        config(['llm_pricing.accurate_usage_cost' => true]);

        $usage = $this->buildUsage('anthropic', 'claude-sonnet-4-6', new Usage(200, 300, cacheWriteInputTokens: 1000, cacheReadInputTokens: 50000));

        $this->assertSame(24, $usage->costCredits);
        $this->assertSame(200, $usage->promptTokens);
        $this->assertSame(50000, $usage->cachedInputTokens);
        $this->assertNull($usage->cacheStrategy);
    }

    public function test_gemini_thinking_tokens_are_billed(): void
    {
        config(['llm_pricing.accurate_usage_cost' => true]);

        // 1 000·0.30 + 2 500·2.50 per Mtok = 0.00655 USD → 7 credits (legacy ignored the 2 000 thoughts → 2)
        $usage = $this->buildUsage('google', 'gemini-2.5-flash', new Usage(1000, 500, thoughtTokens: 2000), caching: false);

        $this->assertSame(7, $usage->costCredits);
        $this->assertSame(2500, $usage->completionTokens);
    }

    public function test_platform_credits_use_cache_write_tokens(): void
    {
        $calculator = new CostCalculator;

        $accurate = $calculator->calculatePlatformCredits('anthropic', 'claude-sonnet-4-6', 200, 300, 50000, marginOverride: 1.0, cacheWriteInputTokens: 1000);
        $legacy = $calculator->calculatePlatformCredits('anthropic', 'claude-sonnet-4-6', 200, 300, 50000, CostCalculator::CACHE_STRATEGY_5M, marginOverride: 1.0);

        $this->assertEqualsWithDelta(0.02385, $accurate['raw_cost_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.00531, $legacy['raw_cost_usd'], 1e-9);
    }
}
