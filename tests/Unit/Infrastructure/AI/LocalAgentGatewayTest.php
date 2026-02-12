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
    protected function setUp(): void
    {
        parent::setUp();

        // Set up llm_providers config so resolveAgentKey works
        config(['llm_providers.codex' => [
            'name' => 'Codex (Local)',
            'local' => true,
            'agent_key' => 'codex',
            'models' => [
                'gpt-5.3-codex' => ['label' => 'GPT-4o', 'input_cost' => 0, 'output_cost' => 0],
            ],
        ]]);
        config(['llm_providers.claude-code' => [
            'name' => 'Claude Code (Local)',
            'local' => true,
            'agent_key' => 'claude-code',
            'models' => [
                'claude-sonnet-4-5' => ['label' => 'Claude Sonnet 4.5', 'input_cost' => 0, 'output_cost' => 0],
            ],
        ]]);
    }

    public function test_complete_throws_for_unknown_agent(): void
    {
        $discovery = Mockery::mock(LocalAgentDiscovery::class);

        $gateway = new LocalAgentGateway($discovery);

        // Provider 'nonexistent' has no llm_providers config, so agent_key defaults to 'nonexistent'
        $request = new AiRequestDTO(
            provider: 'nonexistent',
            model: 'some-model',
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
        $discovery->shouldReceive('isBridgeMode')->andReturn(false);
        $discovery->shouldReceive('binaryPath')
            ->with('codex')
            ->andReturn(null);

        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'codex',
            model: 'gpt-5.3-codex',
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
            provider: 'codex',
            model: 'gpt-5.3-codex',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $this->assertEquals(0, $gateway->estimateCost($request));
    }

    public function test_complete_with_echo_binary(): void
    {
        // Set up a provider with agent_key that maps to a non-standard agent
        config(['llm_providers.echo-local' => [
            'name' => 'Echo (Local)',
            'local' => true,
            'agent_key' => 'echo-agent',
            'models' => [],
        ]]);

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
        $discovery->shouldReceive('isBridgeMode')->andReturn(false);
        $discovery->shouldReceive('binaryPath')
            ->with('echo-agent')
            ->andReturn('/bin/echo');

        $gateway = new LocalAgentGateway($discovery);

        // The gateway will try to run a command built from buildCommand() which
        // matches only 'codex' and 'claude-code' — so this will throw
        $request = new AiRequestDTO(
            provider: 'echo-local',
            model: 'some-model',
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
