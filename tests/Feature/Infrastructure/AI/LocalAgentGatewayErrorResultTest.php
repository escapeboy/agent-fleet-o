<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Exceptions\LocalAgentErrorResultException;
use App\Infrastructure\AI\Exceptions\LocalAgentRateLimitException;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Claude Code results that report an error (is_error, an error_* subtype or a
 * reached usage limit) must not be returned as the agent's answer.
 */
class LocalAgentGatewayErrorResultTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['llm_providers.claude-code' => [
            'name' => 'Claude Code (Local)',
            'local' => true,
            'agent_key' => 'claude-code',
            'models' => [
                'claude-sonnet-4-5' => ['label' => 'Claude Sonnet 4.5', 'input_cost' => 0, 'output_cost' => 0],
            ],
        ]]);

        config(['local_agents.timeout' => 60]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/claude-code/'.$name));
    }

    /**
     * @param  array<string, mixed>  $bridgeResponse
     */
    private function runBridge(array $bridgeResponse): AiResponseDTO
    {
        /** @var LocalAgentDiscovery&MockInterface $discovery */
        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('agentConfig')->andReturn(['name' => 'Claude Code', 'binary' => 'claude']);
        $discovery->shouldReceive('isBridgeMode')->andReturn(true);
        $discovery->shouldReceive('bridgeUrl')->andReturn('http://bridge.test:9000');
        $discovery->shouldReceive('bridgeSecret')->andReturn('test-secret');

        Http::fake([
            'http://bridge.test:9000/execute' => Http::response($bridgeResponse, 200),
        ]);

        $gateway = new LocalAgentGateway($discovery);

        return $gateway->complete(new AiRequestDTO(
            provider: 'claude-code',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            teamId: $this->team->id,
            userId: $this->user->id,
        ));
    }

    public function test_is_error_result_throws_error_result_exception(): void
    {
        try {
            $this->runBridge(['success' => true, 'output' => $this->fixture('is-error.json'), 'execution_time_ms' => 5]);
            $this->fail('Expected LocalAgentErrorResultException');
        } catch (LocalAgentErrorResultException $e) {
            $this->assertNotInstanceOf(LocalAgentRateLimitException::class, $e);
            $this->assertSame('success', $e->subtype);
            $this->assertStringContainsString("Local agent 'Claude Code' reported an error (success)", $e->getMessage());
            $this->assertStringContainsString('API Error: 500', $e->getMessage());
        }
    }

    public function test_rejected_rate_limit_throws_rate_limit_exception(): void
    {
        try {
            $this->runBridge(['success' => true, 'output' => $this->fixture('rate-limit-rejected.jsonl'), 'execution_time_ms' => 5]);
            $this->fail('Expected LocalAgentRateLimitException');
        } catch (LocalAgentRateLimitException $e) {
            $this->assertNotNull($e->resetsAt);
            $this->assertSame(1767225600, $e->resetsAt->getTimestamp());
            $this->assertSame('five_hour', $e->limitType);
            $this->assertStringContainsString('five_hour', $e->getMessage());
            $this->assertStringContainsString('2026-01-01T00:00:00', $e->getMessage());
            $this->assertSame(0, $e->secondsUntilReset());
        }
    }

    public function test_exit_one_recovery_does_not_swallow_error_result(): void
    {
        $this->expectException(LocalAgentErrorResultException::class);
        $this->expectExceptionMessage('error_max_turns');

        $this->runBridge([
            'success' => false,
            'exit_code' => 1,
            'output' => $this->fixture('error-max-turns.jsonl'),
            'error' => 'Process exited with non-zero code',
        ]);
    }

    public function test_successful_result_still_returns_response(): void
    {
        $response = $this->runBridge(['success' => true, 'output' => $this->fixture('success.json'), 'execution_time_ms' => 5]);

        $this->assertSame('ok', $response->content);
    }
}
