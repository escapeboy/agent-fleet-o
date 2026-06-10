<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression guard for the 2026-06-10 incident: an agent on provider=openrouter
 * with the canonical model id "claude-sonnet-4-5" hit
 * "OpenRouter Bad Request: claude-sonnet-4-5 is not a valid model ID", failed
 * 5+ times and opened the per-agent circuit breaker. The gateway now auto-
 * translates canonical ids to OpenRouter vendor-prefixed ids.
 */
class OpenRouterModelAliasTest extends TestCase
{
    private function normalize(AiRequestDTO $request): AiRequestDTO
    {
        $method = new ReflectionMethod(PrismAiGateway::class, 'normalizeOpenRouterModel');

        return $method->invoke(app(PrismAiGateway::class), $request);
    }

    private function request(string $provider, string $model): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'user',
        );
    }

    public function test_translates_canonical_id_to_openrouter_id(): void
    {
        $result = $this->normalize($this->request('openrouter', 'claude-sonnet-4-5'));

        $this->assertSame('anthropic/claude-sonnet-4.5', $result->model);
    }

    public function test_leaves_already_prefixed_openrouter_id_untouched(): void
    {
        $result = $this->normalize($this->request('openrouter', 'anthropic/claude-sonnet-4.5'));

        $this->assertSame('anthropic/claude-sonnet-4.5', $result->model);
    }

    public function test_leaves_non_openrouter_provider_untouched(): void
    {
        $result = $this->normalize($this->request('anthropic', 'claude-sonnet-4-5'));

        $this->assertSame('claude-sonnet-4-5', $result->model);
    }

    public function test_unknown_bare_id_passes_through_with_warning(): void
    {
        Log::shouldReceive('warning')->once();

        $result = $this->normalize($this->request('openrouter', 'some-unmapped-model'));

        $this->assertSame('some-unmapped-model', $result->model);
    }
}
