<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use Illuminate\Support\Facades\Http;

class BridgeControllerTest extends ApiTestCase
{
    // -----------------------------------------------------------------------
    // POST /api/v1/bridge/connect — HTTP tunnel mode
    // -----------------------------------------------------------------------

    public function test_connect_creates_http_mode_connection_when_discover_succeeds(): void
    {
        Http::fake([
            'https://abc123.trycloudflare.com/discover' => Http::response([
                'agents' => [['key' => 'claude-code', 'name' => 'Claude Code', 'found' => true, 'version' => '2.1.79']],
                'llm_endpoints' => [],
                'mcp_servers' => [],
            ], 200),
        ]);

        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/bridge/connect', [
            'endpoint_url' => 'https://abc123.trycloudflare.com',
            'tunnel_provider' => 'cloudflare',
            'label' => 'Home Server',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.endpoint_url', 'https://abc123.trycloudflare.com')
            ->assertJsonPath('data.tunnel_provider', 'cloudflare')
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('bridge_connections', [
            'team_id' => $this->team->id,
            'endpoint_url' => 'https://abc123.trycloudflare.com',
            'status' => BridgeConnectionStatus::Connected->value,
        ]);
    }

    public function test_connect_returns_422_when_bridge_server_unreachable(): void
    {
        Http::fake([
            'https://bad-url.example.com/discover' => Http::response('Connection refused', 502),
        ]);

        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/bridge/connect', [
            'endpoint_url' => 'https://bad-url.example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['error']);

        $this->assertDatabaseMissing('bridge_connections', [
            'team_id' => $this->team->id,
            'endpoint_url' => 'https://bad-url.example.com',
        ]);
    }

    public function test_connect_requires_valid_url(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/bridge/connect', [
            'endpoint_url' => 'not-a-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint_url']);
    }

    public function test_connect_upserts_existing_connection_for_same_url(): void
    {
        Http::fake([
            'https://abc123.trycloudflare.com/discover' => Http::response([
                'agents' => [],
                'llm_endpoints' => [],
                'mcp_servers' => [],
            ], 200),
        ]);

        $existing = BridgeConnection::create([
            'team_id' => $this->team->id,
            'endpoint_url' => 'https://abc123.trycloudflare.com',
            'status' => BridgeConnectionStatus::Disconnected,
            'endpoints' => [],
            'connected_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);

        $this->actingAsApiUser();

        $this->postJson('/api/v1/bridge/connect', [
            'endpoint_url' => 'https://abc123.trycloudflare.com',
            'label' => 'Updated Label',
        ])->assertStatus(201);

        // Should not create a duplicate
        $this->assertDatabaseCount('bridge_connections', 1);
        $existing->refresh();
        $this->assertEquals(BridgeConnectionStatus::Connected, $existing->status);
    }

    // -----------------------------------------------------------------------
    // PUT /api/v1/bridge/{connection}/url
    // -----------------------------------------------------------------------

    public function test_update_url_changes_endpoint(): void
    {
        $connection = BridgeConnection::create([
            'team_id' => $this->team->id,
            'endpoint_url' => 'https://old-url.trycloudflare.com',
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => [],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAsApiUser();

        $this->putJson("/api/v1/bridge/{$connection->id}/url", [
            'endpoint_url' => 'https://new-url.trycloudflare.com',
        ])->assertOk()->assertJsonPath('data.endpoint_url', 'https://new-url.trycloudflare.com');

        $connection->refresh();
        $this->assertEquals('https://new-url.trycloudflare.com', $connection->endpoint_url);
    }

    public function test_update_url_forbidden_for_other_team(): void
    {
        $otherTeam = \App\Domain\Shared\Models\Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);

        $connection = BridgeConnection::create([
            'team_id' => $otherTeam->id,
            'endpoint_url' => 'https://other.trycloudflare.com',
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => [],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAsApiUser();

        $this->putJson("/api/v1/bridge/{$connection->id}/url", [
            'endpoint_url' => 'https://hijack.example.com',
        ])->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/bridge/{connection}/ping
    // -----------------------------------------------------------------------

    public function test_ping_updates_status_to_connected_when_health_ok(): void
    {
        $connection = BridgeConnection::create([
            'team_id' => $this->team->id,
            'endpoint_url' => 'https://home.trycloudflare.com',
            'status' => BridgeConnectionStatus::Disconnected,
            'endpoints' => [],
            'connected_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://home.trycloudflare.com/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $this->actingAsApiUser();

        $this->postJson("/api/v1/bridge/{$connection->id}/ping")
            ->assertOk()
            ->assertJsonPath('data.online', true)
            ->assertJsonPath('data.status', 'connected');

        $connection->refresh();
        $this->assertEquals(BridgeConnectionStatus::Connected, $connection->status);
    }

    public function test_ping_updates_status_to_disconnected_when_health_fails(): void
    {
        $connection = BridgeConnection::create([
            'team_id' => $this->team->id,
            'endpoint_url' => 'https://offline.trycloudflare.com',
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => [],
            'connected_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://offline.trycloudflare.com/health' => Http::response('', 502),
        ]);

        $this->actingAsApiUser();

        $this->postJson("/api/v1/bridge/{$connection->id}/ping")
            ->assertOk()
            ->assertJsonPath('data.online', false);

        $connection->refresh();
        $this->assertEquals(BridgeConnectionStatus::Disconnected, $connection->status);
    }
}
