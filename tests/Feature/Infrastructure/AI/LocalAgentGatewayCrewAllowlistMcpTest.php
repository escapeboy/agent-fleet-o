<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Services\AgentToolAllowlistContext;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\Api\V1\ApiTestCase;

/**
 * Claude Code crew members must only get the MCP tools in their CrewMember
 * tool_allowlist (carried via AgentToolAllowlistContext).
 */
class LocalAgentGatewayCrewAllowlistMcpTest extends ApiTestCase
{
    private Agent $agent;

    private Tool $toolA;

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

        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $this->toolA = $this->attachMcpTool('tool-alpha', 'https://alpha-mcp.example.test');
        $this->attachMcpTool('tool-beta', 'https://beta-mcp.example.test');

        Http::fake([
            'http://bridge.test:9000/execute' => Http::response([
                'success' => true,
                'output' => '{"type":"result","result":"ok"}',
                'execution_time_ms' => 12,
            ], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_only_allowlisted_tools_are_sent_to_bridge(): void
    {
        app(AgentToolAllowlistContext::class)->with(
            $this->agent->id,
            [$this->toolA->id],
            fn () => $this->runGateway(),
        );

        $this->assertSame(['tool_alpha'], array_keys($this->sentServers()));
    }

    public function test_no_mcp_servers_when_allowlist_matches_no_attached_tool(): void
    {
        app(AgentToolAllowlistContext::class)->with(
            $this->agent->id,
            ['00000000-0000-0000-0000-000000000000'],
            fn () => $this->runGateway(),
        );

        $this->assertSame([], $this->sentServers());
    }

    public function test_all_tools_are_sent_without_allowlist(): void
    {
        $this->runGateway();

        $servers = array_keys($this->sentServers());
        sort($servers);

        $this->assertSame(['tool_alpha', 'tool_beta'], $servers);
    }

    public function test_allowlist_for_other_agent_does_not_apply(): void
    {
        $other = Agent::factory()->create(['team_id' => $this->team->id]);

        app(AgentToolAllowlistContext::class)->with(
            $other->id,
            [$this->toolA->id],
            fn () => $this->runGateway(),
        );

        $servers = array_keys($this->sentServers());
        sort($servers);

        $this->assertSame(['tool_alpha', 'tool_beta'], $servers);
    }

    private function attachMcpTool(string $slug, string $url): Tool
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => $slug,
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => $url],
            'credentials' => ['api_key' => 'sk-fake-token'],
        ]);

        $this->agent->tools()->attach($tool->id, [
            'priority' => 0,
            'overrides' => json_encode([]),
            'approval_mode' => 'auto',
            'approval_timeout_minutes' => 0,
            'approval_timeout_action' => 'allow',
        ]);

        return $tool;
    }

    private function runGateway(): void
    {
        /** @var LocalAgentDiscovery&MockInterface $discovery */
        $discovery = Mockery::mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('agentConfig')->andReturn(['name' => 'Claude Code', 'binary' => 'claude']);
        $discovery->shouldReceive('isBridgeMode')->andReturn(true);
        $discovery->shouldReceive('bridgeUrl')->andReturn('http://bridge.test:9000');
        $discovery->shouldReceive('bridgeSecret')->andReturn('test-secret');

        (new LocalAgentGateway($discovery))->complete(new AiRequestDTO(
            provider: 'claude-code',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            agentId: $this->agent->id,
            teamId: $this->team->id,
            userId: $this->user->id,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function sentServers(): array
    {
        $recorded = Http::recorded(
            fn (HttpClientRequest $req) => $req->url() === 'http://bridge.test:9000/execute',
        );

        $this->assertCount(1, $recorded);

        $body = $recorded[0][0]->data();
        $this->assertArrayHasKey('mcp_servers', $body);

        $servers = json_decode((string) json_encode($body['mcp_servers']), true);
        $this->assertIsArray($servers);

        return $servers;
    }
}
