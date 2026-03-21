<?php

namespace Tests\Unit\Domain\Bridge;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Services\BridgeRouter;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BridgeRouterMcpTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private BridgeRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'plan' => 'pro',
            'settings' => [],
        ]);

        $this->router = app(BridgeRouter::class);
    }

    public function test_resolve_for_mcp_server_finds_matching_bridge(): void
    {
        $bridge = BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-test-1',
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => [
                'agents' => [],
                'mcp_servers' => [
                    ['name' => 'playwright'],
                    ['name' => 'filesystem'],
                ],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $result = $this->router->resolveForMcpServer($this->team->id, 'playwright');

        $this->assertNotNull($result);
        $this->assertEquals($bridge->id, $result->id);
    }

    public function test_resolve_for_mcp_server_returns_null_when_not_found(): void
    {
        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-test-1',
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => [
                'agents' => [],
                'mcp_servers' => [
                    ['name' => 'filesystem'],
                ],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $result = $this->router->resolveForMcpServer($this->team->id, 'playwright');

        $this->assertNull($result);
    }

    public function test_resolve_for_mcp_server_ignores_disconnected_bridges(): void
    {
        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-test-1',
            'status' => BridgeConnectionStatus::Disconnected,
            'endpoints' => [
                'agents' => [],
                'mcp_servers' => [
                    ['name' => 'playwright'],
                ],
            ],
            'connected_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);

        $result = $this->router->resolveForMcpServer($this->team->id, 'playwright');

        $this->assertNull($result);
    }

    public function test_resolve_for_mcp_server_scoped_to_team(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'plan' => 'pro',
            'settings' => [],
        ]);

        BridgeConnection::create([
            'team_id' => $otherTeam->id,
            'session_id' => 'relay-other-1',
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => [
                'agents' => [],
                'mcp_servers' => [
                    ['name' => 'playwright'],
                ],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Should not find the other team's bridge
        $result = $this->router->resolveForMcpServer($this->team->id, 'playwright');

        $this->assertNull($result);
    }
}
