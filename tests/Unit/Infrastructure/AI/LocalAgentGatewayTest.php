<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LocalAgentGatewayTest extends TestCase
{
    public function test_complete_throws_for_unknown_agent(): void
    {
        $discovery = Mockery::mock(LocalAgentDiscovery::class);

        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'local',
            model: 'nonexistent',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown local agent: nonexistent');

        $gateway->complete($request);
    }

    public function test_complete_throws_when_binary_not_found(): void
    {
        config(['local_agents.agents' => [
            'codex' => [
                'name' => 'OpenAI Codex',
                'binary' => 'codex',
                'detect_command' => 'codex --version',
                'requires_env' => 'OPENAI_API_KEY',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('binaryPath')
            ->with('codex')
            ->andReturn(null);

        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'local',
            model: 'codex',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $gateway->complete($request);
    }

    public function test_estimate_cost_returns_zero(): void
    {
        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'local',
            model: 'codex',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $this->assertEquals(0, $gateway->estimateCost($request));
    }

    public function test_complete_with_echo_binary(): void
    {
        // Use 'echo' as a fake local agent that outputs JSON
        config(['local_agents.agents' => [
            'echo-agent' => [
                'name' => 'Echo Agent',
                'binary' => 'echo',
                'detect_command' => 'echo ok',
                'requires_env' => '',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);
        config(['local_agents.timeout' => 10]);

        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('binaryPath')
            ->with('echo-agent')
            ->andReturn('/bin/echo');

        $gateway = new LocalAgentGateway($discovery);

        // The gateway will try to run a command built from buildCommand() which
        // matches only 'codex' and 'claude-code' — so this will throw
        $request = new AiRequestDTO(
            provider: 'local',
            model: 'echo-agent',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No command template');

        $gateway->complete($request);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
