<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Bridge\BridgeDisconnectTool;
use App\Mcp\Tools\Bridge\BridgeEndpointListTool;
use App\Mcp\Tools\Bridge\BridgeEndpointToggleTool;
use App\Mcp\Tools\Bridge\BridgeListTool;
use App\Mcp\Tools\Bridge\BridgeRenameTool;
use App\Mcp\Tools\Bridge\BridgeSetRoutingTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class BridgeToolsRequestArgumentsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private BridgeConnection $connected;

    private BridgeConnection $disconnected;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Bridge Args Team',
            'slug' => 'bridge-args-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);

        $this->connected = BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'session-connected',
            'label' => 'Primary',
            'priority' => 10,
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => [
                'llm_endpoints' => [
                    ['id' => 'llm-1', 'name' => 'Local LLM', 'online' => true, 'enabled' => true],
                ],
                'agents' => [
                    ['id' => 'agent-1', 'key' => 'agent-1', 'found' => true, 'enabled' => true],
                ],
                'mcp_servers' => [],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->disconnected = BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'session-disconnected',
            'label' => 'Old',
            'priority' => 1,
            'status' => BridgeConnectionStatus::Disconnected,
            'endpoints' => [],
            'connected_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
            'disconnected_at' => now()->subHours(20),
        ]);
    }

    public function test_bridge_list_filters_by_status(): void
    {
        $data = $this->decode((new BridgeListTool)->handle(new Request(['status' => 'connected'])));

        $this->assertSame(1, $data['total']);
        $this->assertSame($this->connected->id, $data['connections'][0]['id']);
    }

    public function test_bridge_list_defaults_to_all(): void
    {
        $data = $this->decode((new BridgeListTool)->handle(new Request([])));

        $this->assertSame(2, $data['total']);
        $this->assertContains($this->disconnected->id, array_column($data['connections'], 'id'));
    }

    public function test_bridge_endpoint_list_filters_by_type(): void
    {
        $data = $this->decode((new BridgeEndpointListTool)->handle(new Request(['type' => 'llm'])));

        $this->assertTrue($data['connected']);
        $this->assertArrayHasKey('llm_endpoints', $data);
        $this->assertArrayNotHasKey('agents', $data);
        $this->assertSame('llm-1', $data['llm_endpoints'][0]['id']);
    }

    public function test_bridge_endpoint_toggle_disables_endpoint(): void
    {
        $data = $this->decode((new BridgeEndpointToggleTool)->handle(new Request([
            'endpoint_id' => 'llm-1',
            'type' => 'llm',
            'enabled' => false,
        ])));

        $this->assertTrue($data['success']);
        $this->assertFalse($data['enabled']);
        $this->assertFalse($this->connected->fresh()?->llmEndpoints()[0]['enabled']);
    }

    public function test_bridge_set_routing_saves_prefer_mode(): void
    {
        $data = $this->decode((new BridgeSetRoutingTool)->handle(new Request([
            'routing_mode' => 'prefer',
            'preferred_bridge_id' => $this->connected->id,
        ])));

        $this->assertTrue($data['success']);
        $settings = $this->team->fresh()?->settings;
        $this->assertSame('prefer', $settings['bridge']['routing_mode']);
        $this->assertSame($this->connected->id, $settings['bridge']['preferred_bridge_id']);
    }

    public function test_bridge_rename_updates_label(): void
    {
        $data = $this->decode((new BridgeRenameTool)->handle(new Request([
            'connection_id' => $this->connected->id,
            'label' => 'Dev Laptop',
        ])));

        $this->assertTrue($data['success']);
        $this->assertSame('Dev Laptop', $this->connected->fresh()?->label);
    }

    public function test_bridge_disconnect_terminates_given_connection(): void
    {
        $data = $this->decode((new BridgeDisconnectTool)->handle(new Request([
            'connection_id' => $this->connected->id,
        ])));

        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['disconnected']);
        $this->assertSame(BridgeConnectionStatus::Disconnected, $this->connected->fresh()?->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $data = json_decode((string) $response->content(), true);
        $this->assertIsArray($data);

        return $data;
    }
}
