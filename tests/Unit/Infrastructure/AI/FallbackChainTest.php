<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Gateways\FallbackAiGateway;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Services\CircuitBreaker;
use RuntimeException;
use Tests\TestCase;

class FallbackChainTest extends TestCase
{
    private PrismAiGateway $gateway;

    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(PrismAiGateway::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);
    }

    private function makeResponse(string $provider = 'anthropic', string $model = 'claude-sonnet-4-5'): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'test response',
            parsedOutput: null,
            usage: new AiUsageDTO(
                promptTokens: 100,
                completionTokens: 50,
                costCredits: 10,
            ),
            provider: $provider,
            model: $model,
            latencyMs: 100,
        );
    }

    private function makeRequest(
        string $provider = 'anthropic',
        string $model = 'claude-sonnet-4-5',
        ?array $fallbackChain = null,
    ): AiRequestDTO {
        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'test',
            userPrompt: 'test',
            fallbackChain: $fallbackChain,
        );
    }

    public function test_request_fallback_chain_takes_priority_over_global(): void
    {
        $globalChains = [
            'anthropic/claude-sonnet-4-5' => [
                ['provider' => 'openai', 'model' => 'gpt-4o'],
            ],
        ];

        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker, $globalChains);

        $this->circuitBreaker->method('isAvailable')->willReturn(true);

        // Primary fails
        $callCount = 0;
        $this->gateway->method('complete')->willReturnCallback(function (AiRequestDTO $req) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('Primary failed');
            }
            // Should hit the request-level fallback (google), NOT the global one (openai)
            $this->assertEquals('google', $req->provider);
            $this->assertEquals('gemini-2.5-flash', $req->model);

            return $this->makeResponse('google', 'gemini-2.5-flash');
        });

        $request = $this->makeRequest(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            fallbackChain: [['provider' => 'google', 'model' => 'gemini-2.5-flash']],
        );

        $response = $fallbackGateway->complete($request);
        $this->assertEquals('google', $response->provider);
    }

    public function test_global_chain_used_when_no_request_chain(): void
    {
        $globalChains = [
            'anthropic/claude-sonnet-4-5' => [
                ['provider' => 'openai', 'model' => 'gpt-4o'],
            ],
        ];

        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker, $globalChains);

        $this->circuitBreaker->method('isAvailable')->willReturn(true);

        $callCount = 0;
        $this->gateway->method('complete')->willReturnCallback(function (AiRequestDTO $req) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('Primary failed');
            }
            $this->assertEquals('openai', $req->provider);
            $this->assertEquals('gpt-4o', $req->model);

            return $this->makeResponse('openai', 'gpt-4o');
        });

        $request = $this->makeRequest(provider: 'anthropic', model: 'claude-sonnet-4-5');
        $response = $fallbackGateway->complete($request);
        $this->assertEquals('openai', $response->provider);
    }

    public function test_empty_request_chain_falls_through_to_global(): void
    {
        $globalChains = [
            'anthropic/claude-sonnet-4-5' => [
                ['provider' => 'openai', 'model' => 'gpt-4o'],
            ],
        ];

        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker, $globalChains);

        $this->circuitBreaker->method('isAvailable')->willReturn(true);

        $callCount = 0;
        $this->gateway->method('complete')->willReturnCallback(function (AiRequestDTO $req) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('Primary failed');
            }

            return $this->makeResponse('openai', 'gpt-4o');
        });

        // Empty array should NOT be treated as a request chain
        $request = $this->makeRequest(provider: 'anthropic', model: 'claude-sonnet-4-5', fallbackChain: []);
        $response = $fallbackGateway->complete($request);
        $this->assertEquals('openai', $response->provider);
    }

    public function test_circuit_breaker_skips_unavailable_in_custom_chain(): void
    {
        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker);

        $this->circuitBreaker->method('isAvailable')->willReturnCallback(function (string $provider) {
            // Google is unavailable (circuit open)
            return $provider !== 'google';
        });

        $callCount = 0;
        $this->gateway->method('complete')->willReturnCallback(function (AiRequestDTO $req) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('Primary failed');
            }
            // Should skip google and hit openai
            $this->assertEquals('openai', $req->provider);

            return $this->makeResponse('openai', 'gpt-4o');
        });

        $request = $this->makeRequest(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            fallbackChain: [
                ['provider' => 'google', 'model' => 'gemini-2.5-flash'],
                ['provider' => 'openai', 'model' => 'gpt-4o'],
            ],
        );

        $response = $fallbackGateway->complete($request);
        $this->assertEquals('openai', $response->provider);
    }

    public function test_local_agent_without_gateway_throws(): void
    {
        // In open-source, local agents use provider names like 'codex' or 'claude-code'
        // with a 'local' => true flag in llm_providers config
        config(['llm_providers.codex.local' => true]);

        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker);

        $request = $this->makeRequest(provider: 'codex', model: 'gpt-5.3-codex');

        // Without localGateway, local provider requests throw
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Local agent provider 'codex' is not available");
        $fallbackGateway->complete($request);
    }
}
