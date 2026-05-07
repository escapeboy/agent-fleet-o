<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class PrismAiGatewayCacheWiringTest extends TestCase
{
    private function gateway(): PrismAiGateway
    {
        return new PrismAiGateway(new CostCalculator);
    }

    private function callPrivate(PrismAiGateway $gw, string $method, array $args): mixed
    {
        $reflection = new \ReflectionClass($gw);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($gw, $args);
    }

    public function test_build_usage_extracts_cache_read_tokens(): void
    {
        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            systemPrompt: 'sys',
            userPrompt: 'user',
            enablePromptCaching: true,
        );

        $usage = new Usage(
            promptTokens: 5000,
            completionTokens: 2000,
            cacheReadInputTokens: 4000,
        );

        /** @var AiUsageDTO $result */
        $result = $this->callPrivate($this->gateway(), 'buildUsageDTO', [$usage, $request]);

        $this->assertSame(5000, $result->promptTokens);
        $this->assertSame(2000, $result->completionTokens);
        $this->assertSame(4000, $result->cachedInputTokens);
        $this->assertSame(CostCalculator::CACHE_STRATEGY_5M, $result->cacheStrategy);
        $this->assertGreaterThan(0, $result->costCredits);
    }

    public function test_build_usage_no_cache_returns_zero_and_null_strategy(): void
    {
        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            systemPrompt: 'sys',
            userPrompt: 'user',
            enablePromptCaching: false,
        );

        $usage = new Usage(promptTokens: 1000, completionTokens: 500);

        /** @var AiUsageDTO $result */
        $result = $this->callPrivate($this->gateway(), 'buildUsageDTO', [$usage, $request]);

        $this->assertSame(0, $result->cachedInputTokens);
        $this->assertNull($result->cacheStrategy);
    }

    public function test_resolve_cache_strategy_only_anthropic_with_caching_enabled(): void
    {
        $gw = $this->gateway();

        $base = ['provider' => 'anthropic', 'model' => 'm', 'systemPrompt' => '', 'userPrompt' => ''];

        $this->assertSame(
            CostCalculator::CACHE_STRATEGY_5M,
            $this->callPrivate($gw, 'resolveCacheStrategy', [
                new AiRequestDTO(...$base, enablePromptCaching: true),
            ]),
        );

        $this->assertNull($this->callPrivate($gw, 'resolveCacheStrategy', [
            new AiRequestDTO(...$base, enablePromptCaching: false),
        ]));

        $this->assertNull($this->callPrivate($gw, 'resolveCacheStrategy', [
            new AiRequestDTO(provider: 'openai', model: 'gpt-4o', systemPrompt: '', userPrompt: '', enablePromptCaching: true),
        ]));
    }

    public function test_usage_dto_default_cache_fields(): void
    {
        // Back-compat: callers that don't pass cache fields get safe defaults.
        $u = new AiUsageDTO(
            promptTokens: 100,
            completionTokens: 50,
            costCredits: 5,
        );

        $this->assertSame(0, $u->cachedInputTokens);
        $this->assertNull($u->cacheStrategy);
    }
}
