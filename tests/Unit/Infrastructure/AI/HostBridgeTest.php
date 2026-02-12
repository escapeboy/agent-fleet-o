<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'local_agents.enabled' => true,
            'local_agents.agents' => [
                'codex' => [
                    'name' => 'OpenAI Codex',
                    'binary' => 'codex',
                    'description' => 'AI coding agent by OpenAI',
                    'detect_command' => 'codex --version',
                    'requires_env' => 'OPENAI_API_KEY',
                    'capabilities' => ['code_generation'],
                    'supported_modes' => ['sync'],
                ],
                'claude-code' => [
                    'name' => 'Claude Code',
                    'binary' => 'claude',
                    'description' => 'AI coding agent by Anthropic',
                    'detect_command' => 'claude --version',
                    'requires_env' => 'ANTHROPIC_API_KEY',
                    'capabilities' => ['code_generation'],
                    'supported_modes' => ['sync'],
                ],
            ],
            'local_agents.bridge.auto_detect' => true,
            'local_agents.bridge.url' => 'http://host.docker.internal:8065',
            'local_agents.bridge.secret' => 'test-secret-123',
            'local_agents.bridge.connect_timeout' => 5,
            'local_agents.timeout' => 300,
            // Configure llm_providers for agent key resolution
            'llm_providers.codex' => [
                'name' => 'Codex (Local)',
                'local' => true,
                'agent_key' => 'codex',
                'models' => [
                    'gpt-5.3-codex' => ['label' => 'GPT-5.3 Codex', 'input_cost' => 0, 'output_cost' => 0],
                ],
            ],
            'llm_providers.claude-code' => [
                'name' => 'Claude Code (Local)',
                'local' => true,
                'agent_key' => 'claude-code',
                'models' => [
                    'claude-sonnet-4-5' => ['label' => 'Claude Sonnet 4.5', 'input_cost' => 0, 'output_cost' => 0],
                ],
            ],
        ]);
    }

    private function simulateDocker(): void
    {
        putenv('RUNNING_IN_DOCKER=true');
        $_ENV['RUNNING_IN_DOCKER'] = 'true';
        $_SERVER['RUNNING_IN_DOCKER'] = 'true';
    }

    private function simulateNative(): void
    {
        putenv('RUNNING_IN_DOCKER=false');
        $_ENV['RUNNING_IN_DOCKER'] = 'false';
        $_SERVER['RUNNING_IN_DOCKER'] = 'false';
    }

    protected function tearDown(): void
    {
        putenv('RUNNING_IN_DOCKER');
        unset($_ENV['RUNNING_IN_DOCKER'], $_SERVER['RUNNING_IN_DOCKER']);
        parent::tearDown();
    }

    public function test_should_use_bridge_when_in_docker_with_config(): void
    {
        $this->simulateDocker();

        $discovery = new LocalAgentDiscovery();

        $this->assertTrue($discovery->isBridgeMode());
    }

    public function test_should_not_use_bridge_when_not_in_docker(): void
    {
        $this->simulateNative();

        $discovery = new LocalAgentDiscovery();

        $this->assertFalse($discovery->isBridgeMode());
    }

    public function test_should_not_use_bridge_when_secret_empty(): void
    {
        $this->simulateDocker();
        config(['local_agents.bridge.secret' => '']);

        $discovery = new LocalAgentDiscovery();

        $this->assertFalse($discovery->isBridgeMode());
    }

    public function test_bridge_discover_returns_agents(): void
    {
        $this->simulateDocker();

        Http::fake([
            'host.docker.internal:8065/discover' => Http::response([
                'agents' => [
                    'codex' => [
                        'name' => 'OpenAI Codex',
                        'version' => '1.0.0',
                        'path' => '/usr/local/bin/codex',
                    ],
                    'claude-code' => [
                        'name' => 'Claude Code',
                        'version' => '2.1.0',
                        'path' => '/usr/local/bin/claude',
                    ],
                ],
            ]),
        ]);

        $discovery = new LocalAgentDiscovery();
        $detected = $discovery->detect();

        $this->assertCount(2, $detected);
        $this->assertArrayHasKey('codex', $detected);
        $this->assertArrayHasKey('claude-code', $detected);
        $this->assertEquals('1.0.0', $detected['codex']['version']);
        $this->assertEquals('/usr/local/bin/codex', $detected['codex']['path']);
    }

    public function test_bridge_discover_handles_connection_failure_gracefully(): void
    {
        $this->simulateDocker();

        Http::fake([
            'host.docker.internal:8065/discover' => Http::response(null, 500),
        ]);

        $discovery = new LocalAgentDiscovery();
        $detected = $discovery->detect();

        $this->assertEmpty($detected);
    }

    public function test_bridge_execute_returns_response(): void
    {
        $this->simulateDocker();

        Http::fake([
            'host.docker.internal:8065/discover' => Http::response([
                'agents' => [
                    'codex' => [
                        'name' => 'OpenAI Codex',
                        'version' => '1.0.0',
                        'path' => '/usr/local/bin/codex',
                    ],
                ],
            ]),
            'host.docker.internal:8065/health' => Http::response(['status' => 'ok']),
            'host.docker.internal:8065/execute' => Http::response([
                'success' => true,
                'output' => '{"result": "Hello from codex"}',
                'stderr' => '',
                'exit_code' => 0,
                'execution_time_ms' => 1500,
            ]),
        ]);

        $discovery = new LocalAgentDiscovery();
        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'codex',
            model: 'gpt-5.3-codex',
            systemPrompt: 'You are a helper.',
            userPrompt: 'Say hello',
        );

        $response = $gateway->complete($request);

        $this->assertEquals('Hello from codex', $response->content);
        $this->assertEquals('codex', $response->provider);
        $this->assertEquals('gpt-5.3-codex', $response->model);
        $this->assertEquals(0, $response->usage->costCredits);
        $this->assertEquals(1500, $response->latencyMs);
    }

    public function test_bridge_execute_throws_on_failure_response(): void
    {
        $this->simulateDocker();

        Http::fake([
            'host.docker.internal:8065/discover' => Http::response([
                'agents' => [
                    'codex' => [
                        'name' => 'OpenAI Codex',
                        'version' => '1.0.0',
                        'path' => '/usr/local/bin/codex',
                    ],
                ],
            ]),
            'host.docker.internal:8065/health' => Http::response(['status' => 'ok']),
            'host.docker.internal:8065/execute' => Http::response([
                'success' => false,
                'error' => 'Process exited with code 1',
                'exit_code' => 1,
                'execution_time_ms' => 200,
            ]),
        ]);

        $discovery = new LocalAgentDiscovery();
        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'codex',
            model: 'gpt-5.3-codex',
            systemPrompt: '',
            userPrompt: 'fail',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed via bridge');

        $gateway->complete($request);
    }

    public function test_bridge_execute_throws_on_connection_failure(): void
    {
        $this->simulateDocker();

        Http::fake([
            'host.docker.internal:8065/discover' => Http::response([
                'agents' => [
                    'codex' => [
                        'name' => 'OpenAI Codex',
                        'version' => '1.0.0',
                        'path' => '/usr/local/bin/codex',
                    ],
                ],
            ]),
            'host.docker.internal:8065/health' => Http::response(['status' => 'ok']),
            'host.docker.internal:8065/execute' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $discovery = new LocalAgentDiscovery();
        $gateway = new LocalAgentGateway($discovery);

        $request = new AiRequestDTO(
            provider: 'codex',
            model: 'gpt-5.3-codex',
            systemPrompt: '',
            userPrompt: 'test',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bridge connection failed');

        $gateway->complete($request);
    }

    public function test_native_execution_unchanged_when_not_in_docker(): void
    {
        $this->simulateNative();

        // Override with a non-existent binary to test native path
        config(['local_agents.agents.fake-agent' => [
            'name' => 'Fake Agent',
            'binary' => 'this-binary-definitely-does-not-exist-99999',
            'description' => 'Test',
            'detect_command' => 'this-binary-definitely-does-not-exist-99999 --version',
            'requires_env' => '',
            'capabilities' => [],
            'supported_modes' => ['sync'],
        ]]);

        $discovery = new LocalAgentDiscovery();

        // Not in bridge mode — should use native detection
        $this->assertFalse($discovery->isBridgeMode());

        // Native: non-existent binary returns null
        $this->assertNull($discovery->binaryPath('fake-agent'));
        $this->assertFalse($discovery->isAvailable('fake-agent'));
    }

    public function test_bridge_health_check(): void
    {
        $this->simulateDocker();

        Http::fake([
            'host.docker.internal:8065/health' => Http::response([
                'status' => 'ok',
                'php_version' => '8.4.0',
                'pid' => 12345,
            ]),
        ]);

        $discovery = new LocalAgentDiscovery();

        $this->assertTrue($discovery->bridgeHealth());
    }
}
