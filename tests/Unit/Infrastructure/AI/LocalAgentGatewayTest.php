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
        config(['llm_providers.cursor' => [
            'name' => 'Cursor (Local)',
            'local' => true,
            'agent_key' => 'cursor',
            'models' => [
                'auto'           => ['label' => 'Auto', 'input_cost' => 0, 'output_cost' => 0],
                'sonnet-4'       => ['label' => 'Claude Sonnet 4', 'input_cost' => 0, 'output_cost' => 0],
                'gpt-5'          => ['label' => 'GPT-5', 'input_cost' => 0, 'output_cost' => 0],
                'gemini-2.5-pro' => ['label' => 'Gemini 2.5 Pro', 'input_cost' => 0, 'output_cost' => 0],
                'composer-1.5'   => ['label' => 'Composer 1.5', 'input_cost' => 0, 'output_cost' => 0],
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

    public function test_cursor_binary_not_found_throws_not_available(): void
    {
        config(['local_agents.agents' => [
            'cursor' => [
                'name' => 'Cursor',
                'binary' => 'agent',
                'detect_command' => 'agent --version',
                'requires_env' => 'CURSOR_API_KEY',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('isBridgeMode')->andReturn(false);
        $discovery->shouldReceive('binaryPath')
            ->with('cursor')
            ->andReturn(null);

        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'cursor',
            model: 'auto',
            systemPrompt: 'You are a helpful assistant.',
            userPrompt: 'Write a hello world in PHP',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $gateway->complete($request);
    }

    public function test_cursor_estimate_cost_is_zero(): void
    {
        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'cursor',
            model: 'sonnet-4',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $this->assertEquals(0, $gateway->estimateCost($request));
    }

    public function test_cursor_resolved_via_local_provider_with_model_shortcut(): void
    {
        config(['local_agents.agents' => [
            'cursor' => [
                'name' => 'Cursor',
                'binary' => 'agent',
                'detect_command' => 'agent --version',
                'requires_env' => 'CURSOR_API_KEY',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('isBridgeMode')->andReturn(false);
        // When 'local' provider + model='cursor', resolveAgentKey maps to 'cursor' directly
        // without calling detect() — so binaryPath('cursor') will be called next
        $discovery->shouldReceive('binaryPath')
            ->with('cursor')
            ->andReturn(null);

        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'local',
            model: 'cursor',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        // Should resolve to 'cursor' agent and throw "not available" (binary missing),
        // NOT "Unknown local agent" — proving the resolution path works correctly.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $gateway->complete($request);
    }

    public function test_cursor_unknown_provider_resolves_from_config(): void
    {
        // This tests that resolveAgentKey() falls through to config lookup correctly
        // when provider is 'cursor' directly
        config(['local_agents.agents' => [
            'cursor' => [
                'name' => 'Cursor',
                'binary' => 'agent',
                'detect_command' => 'agent --version',
                'requires_env' => 'CURSOR_API_KEY',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('isBridgeMode')->andReturn(false);
        $discovery->shouldReceive('binaryPath')
            ->with('cursor')
            ->andReturn('/usr/local/bin/agent');

        // If binary is found, buildCommand will be called — it should NOT throw
        // "No command template" (which would mean cursor wasn't in the match).
        // Instead, it will fail at process execution (not a real binary here).
        // We just verify the command template exists by expecting RuntimeException
        // (from process failure, not from "No command template").
        $gateway = new LocalAgentGateway($discovery);

        config(['local_agents.timeout' => 2]);

        $request = new AiRequestDTO(
            provider: 'cursor',
            model: 'auto',
            systemPrompt: 'test',
            userPrompt: 'hello',
        );

        try {
            $gateway->complete($request);
            // If we get here, process ran — that's fine
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(
                'No command template',
                $e->getMessage(),
                'Cursor should have a command template in buildCommand()',
            );
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
