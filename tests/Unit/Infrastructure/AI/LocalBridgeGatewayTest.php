<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\LocalBridgeGateway;
use App\Infrastructure\Bridge\BridgeRequestRegistry;
use Mockery;
use Tests\TestCase;

class LocalBridgeGatewayTest extends TestCase
{
    private BridgeRequestRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = Mockery::mock(BridgeRequestRegistry::class);
    }

    public function test_build_payload_splits_compound_model_key(): void
    {
        $gateway = new LocalBridgeGateway($this->registry);

        $request = new AiRequestDTO(
            provider: 'bridge_agent',
            model: 'claude-code:claude-sonnet-4-5',
            systemPrompt: 'You are helpful',
            userPrompt: 'Hello',
            teamId: 'team-1',
        );

        $connection = Mockery::mock(BridgeConnection::class);

        $payload = $this->invokePrivateMethod($gateway, 'buildPayload', ['req-1', $request, $connection]);

        $this->assertEquals(0x0010, $payload['frame_type']);
        $this->assertEquals('claude-code', $payload['payload']['agent_key']);
        $this->assertEquals('claude-sonnet-4-5', $payload['payload']['model']);
        $this->assertEquals('Hello', $payload['payload']['prompt']);
        $this->assertEquals('You are helpful', $payload['payload']['system_prompt']);
    }

    public function test_build_payload_handles_simple_agent_key_without_model(): void
    {
        $gateway = new LocalBridgeGateway($this->registry);

        $request = new AiRequestDTO(
            provider: 'bridge_agent',
            model: 'kiro',
            systemPrompt: 'System',
            userPrompt: 'Hi',
            teamId: 'team-1',
        );

        $connection = Mockery::mock(BridgeConnection::class);

        $payload = $this->invokePrivateMethod($gateway, 'buildPayload', ['req-2', $request, $connection]);

        $this->assertEquals('kiro', $payload['payload']['agent_key']);
        $this->assertEquals('', $payload['payload']['model']);
    }

    public function test_build_payload_llm_request_uses_frame_type_1(): void
    {
        $gateway = new LocalBridgeGateway($this->registry);

        $request = new AiRequestDTO(
            provider: 'bridge_llm',
            model: 'llama3',
            systemPrompt: 'System',
            userPrompt: 'Hello',
            teamId: 'team-1',
        );

        $connection = Mockery::mock(BridgeConnection::class);
        $connection->shouldReceive('llmEndpoints')->andReturn([
            ['base_url' => 'http://localhost:11434', 'online' => true, 'models' => ['llama3']],
        ]);

        $payload = $this->invokePrivateMethod($gateway, 'buildPayload', ['req-3', $request, $connection]);

        $this->assertEquals(0x0001, $payload['frame_type']);
        $this->assertEquals('llama3', $payload['payload']['model']);
        $this->assertCount(2, $payload['payload']['messages']);
        $this->assertEquals('system', $payload['payload']['messages'][0]['role']);
        $this->assertEquals('user', $payload['payload']['messages'][1]['role']);
    }

    public function test_complete_throws_when_no_team_id(): void
    {
        $gateway = new LocalBridgeGateway($this->registry);

        $request = new AiRequestDTO(
            provider: 'bridge_agent',
            model: 'claude-code:claude-sonnet-4-5',
            systemPrompt: 'test',
            userPrompt: 'test',
            teamId: null,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No team context');

        $gateway->complete($request);
    }

    public function test_estimate_cost_always_returns_zero(): void
    {
        $gateway = new LocalBridgeGateway($this->registry);

        $request = new AiRequestDTO(
            provider: 'bridge_agent',
            model: 'claude-code:claude-sonnet-4-5',
            systemPrompt: 'test',
            userPrompt: 'test',
        );

        $this->assertEquals(0, $gateway->estimateCost($request));
    }

    private function invokePrivateMethod(object $object, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($object, $args);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
