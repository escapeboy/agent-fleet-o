<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Verifies that when LocalAgentGateway dispatches a claude-code execution
 * via the bridge path, the agent's attached MCP tools are forwarded to the
 * bridge so the spawned `claude` process can reach them.
 *
 * Pre-fix the bridge POST body had no MCP context, so the spawned process
 * always fell back to `--mcp-config '{"mcpServers":{}}'` and FleetQ MCP
 * tools (signal_add_comment, bitbucket_*, etc.) were unreachable from the
 * bug-fix-agent loop.
 */
class LocalAgentGatewayBridgeMcpTest extends ApiTestCase
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

        config(['local_agents.agents.claude-code' => [
            'name' => 'Claude Code',
            'binary' => 'claude',
            'detect_command' => 'claude --version',
            'requires_env' => '',
            'capabilities' => [],
            'supported_modes' => ['sync'],
        ]]);

        config(['local_agents.timeout' => 60]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_bridge_post_body_carries_attached_mcp_servers(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'fleetq-fake',
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'https://fake-mcp.example.test'],
            'credentials' => ['api_key' => 'sk-fake-token'],
        ]);

        $agent->tools()->attach($tool->id, [
            'priority' => 0,
            'overrides' => json_encode([]),
            'approval_mode' => 'auto',
            'approval_timeout_minutes' => 0,
            'approval_timeout_action' => 'allow',
        ]);

        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('isBridgeMode')->andReturn(true);
        $discovery->shouldReceive('bridgeUrl')->andReturn('http://bridge.test:9000');
        $discovery->shouldReceive('bridgeSecret')->andReturn('test-secret');

        Http::fake([
            'http://bridge.test:9000/execute' => Http::response([
                'success' => true,
                'output' => '{"type":"result","result":"ok"}',
                'execution_time_ms' => 12,
            ], 200),
        ]);

        $gateway = new LocalAgentGateway($discovery);

        $gateway->complete(new AiRequestDTO(
            provider: 'claude-code',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            agentId: $agent->id,
            teamId: $this->team->id,
            userId: $this->user->id,
        ));

        Http::assertSent(function (HttpClientRequest $req) {
            if ($req->url() !== 'http://bridge.test:9000/execute') {
                return false;
            }

            $body = $req->data();

            if (! array_key_exists('mcp_servers', $body)) {
                return false;
            }

            // The mcp_servers payload may decode as array or stdClass depending on
            // how Http::fake re-serializes — normalize before asserting.
            $servers = json_decode(json_encode($body['mcp_servers']), true);

            if (! is_array($servers) || ! array_key_exists('fleetq_fake', $servers)) {
                return false;
            }

            $entry = $servers['fleetq_fake'];

            return ($entry['type'] ?? null) === 'http'
                && ($entry['url'] ?? null) === 'https://fake-mcp.example.test/mcp'
                && ($entry['headers']['Authorization'] ?? null) === 'Bearer sk-fake-token';
        });
    }

    public function test_bridge_post_body_sends_empty_mcp_servers_when_agent_has_no_tools(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('isBridgeMode')->andReturn(true);
        $discovery->shouldReceive('bridgeUrl')->andReturn('http://bridge.test:9000');
        $discovery->shouldReceive('bridgeSecret')->andReturn('test-secret');

        Http::fake([
            'http://bridge.test:9000/execute' => Http::response([
                'success' => true,
                'output' => '{"type":"result","result":"ok"}',
                'execution_time_ms' => 12,
            ], 200),
        ]);

        $gateway = new LocalAgentGateway($discovery);

        $gateway->complete(new AiRequestDTO(
            provider: 'claude-code',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            agentId: $agent->id,
            teamId: $this->team->id,
            userId: $this->user->id,
        ));

        Http::assertSent(function (HttpClientRequest $req) {
            $body = $req->data();
            $servers = json_decode(json_encode($body['mcp_servers'] ?? null), true);

            // Empty agent → empty object (not absent, not null) so the bridge
            // can distinguish "no tools attached" from "field not provided"
            // and still drop into the empty-default behavior.
            return is_array($servers) && $servers === [];
        });
    }
}
