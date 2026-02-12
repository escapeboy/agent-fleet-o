<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Gateways\FallbackAiGateway;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Services\CircuitBreaker;
use Mockery;
use Tests\TestCase;

class FallbackAiGatewayLocalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Configure codex as a local provider
        config(['llm_providers.codex' => [
            'name' => 'Codex (Local)',
            'local' => true,
            'agent_key' => 'codex',
            'models' => [
                'gpt-5.3-codex' => ['label' => 'GPT-4o', 'input_cost' => 0, 'output_cost' => 0],
            ],
        ]]);
    }

    public function test_routes_local_provider_to_local_gateway(): void
    {
        $prism = Mockery::mock(PrismAiGateway::class);
        $cb = Mockery::mock(CircuitBreaker::class);
        $local = Mockery::mock(LocalAgentGateway::class);

        $expectedResponse = new AiResponseDTO(
            content: 'Hello from local',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
            provider: 'codex',
            model: 'gpt-5.3-codex',
            latencyMs: 100,
        );

        $request = new AiRequestDTO(
            provider: 'codex',
            model: 'gpt-5.3-codex',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $local->shouldReceive('complete')
            ->once()
            ->with($request)
            ->andReturn($expectedResponse);

        // PrismAiGateway should NOT be called
        $prism->shouldNotReceive('complete');

        $gateway = new FallbackAiGateway(
            gateway: $prism,
            circuitBreaker: $cb,
            fallbackChains: [],
            localGateway: $local,
        );

        $response = $gateway->complete($request);

        $this->assertEquals('codex', $response->provider);
        $this->assertEquals('gpt-5.3-codex', $response->model);
        $this->assertEquals('Hello from local', $response->content);
    }

    public function test_estimate_cost_returns_zero_for_local(): void
    {
        $prism = Mockery::mock(PrismAiGateway::class);
        $cb = Mockery::mock(CircuitBreaker::class);
        $local = Mockery::mock(LocalAgentGateway::class);

        $gateway = new FallbackAiGateway(
            gateway: $prism,
            circuitBreaker: $cb,
            fallbackChains: [],
            localGateway: $local,
        );

        $request = new AiRequestDTO(
            provider: 'codex',
            model: 'gpt-5.3-codex',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $this->assertEquals(0, $gateway->estimateCost($request));
    }

    public function test_non_local_requests_use_prism_gateway(): void
    {
        $prism = Mockery::mock(PrismAiGateway::class);
        $cb = Mockery::mock(CircuitBreaker::class);
        $local = Mockery::mock(LocalAgentGateway::class);

        $expectedResponse = new AiResponseDTO(
            content: 'Hello from cloud',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 10),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5-20250929',
            latencyMs: 500,
        );

        $cb->shouldReceive('isAvailable')->andReturn(true);
        $cb->shouldReceive('recordSuccess');

        $prism->shouldReceive('complete')
            ->once()
            ->andReturn($expectedResponse);

        // Local gateway should NOT be called
        $local->shouldNotReceive('complete');

        $gateway = new FallbackAiGateway(
            gateway: $prism,
            circuitBreaker: $cb,
            fallbackChains: [],
            localGateway: $local,
        );

        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5-20250929',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $response = $gateway->complete($request);

        $this->assertEquals('anthropic', $response->provider);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
