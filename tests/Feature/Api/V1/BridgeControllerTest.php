<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
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
        $otherTeam = Team::create([
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

    // -----------------------------------------------------------------------
    // POST /api/v1/broadcasting/auth — channel auth signature (HMAC-SHA256)
    // -----------------------------------------------------------------------

    public function test_broadcasting_auth_authorizes_team_activity_channel_for_matching_team(): void
    {
        config([
            'reverb.apps.apps.0.key' => 'fleetq-key',
            'reverb.apps.apps.0.secret' => 'fleetq-secret',
        ]);

        $this->actingAsApiUser();

        $channel = "private-team.{$this->team->id}.activity";
        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1.2',
            'channel_name' => $channel,
        ]);

        $expectedSignature = hash_hmac('sha256', "1.2:{$channel}", 'fleetq-secret');
        $response->assertOk()
            ->assertJsonPath('auth', "fleetq-key:{$expectedSignature}");
    }

    public function test_broadcasting_auth_rejects_team_activity_channel_for_other_team(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1.2',
            'channel_name' => 'private-team.some-other-team-id.activity',
        ]);

        $response->assertForbidden();
    }

    public function test_broadcasting_auth_still_authorizes_daemon_channel_for_matching_team(): void
    {
        // Regression guard: extending the channel allow-list must not break the
        // bridge daemon's existing `private-daemon.{teamId}` flow.
        config([
            'reverb.apps.apps.0.key' => 'fleetq-key',
            'reverb.apps.apps.0.secret' => 'fleetq-secret',
        ]);

        $this->actingAsApiUser();

        $channel = "private-daemon.{$this->team->id}";
        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1.2',
            'channel_name' => $channel,
        ]);

        $expectedSignature = hash_hmac('sha256', "1.2:{$channel}", 'fleetq-secret');
        $response->assertOk()
            ->assertJsonPath('auth', "fleetq-key:{$expectedSignature}");
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/bridge/mcp-call — HTTP-direct path for HTTP-tunnel-mode bridges
    // -----------------------------------------------------------------------

    public function test_mcp_call_routes_via_http_when_bridge_is_in_http_mode(): void
    {
        $this->actingAsApiUser();

        $bridge = BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'http-mode-session',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://harbormaster.tunnel.example',
            'endpoint_secret' => 'shared-token-xyz',
            'tunnel_provider' => 'cloudflare',
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster', 'tools' => ['list_hosts']]],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://harbormaster.tunnel.example/mcp/harbormaster' => Http::response([
                'result' => [
                    'content' => [['type' => 'text', 'text' => 'friday, hetzner-1']],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/call',
            'params' => ['name' => 'list_hosts', 'arguments' => []],
            'timeout' => 30,
        ]);

        $response->assertOk()
            ->assertJsonPath('result.content.0.text', 'friday, hetzner-1');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://harbormaster.tunnel.example/mcp/harbormaster'
                && $request->hasHeader('Authorization', 'Bearer shared-token-xyz')
                && $request['method'] === 'tools/call'
                && $request['params']['name'] === 'list_hosts'
                && $request['timeout'] === 30
                && ! empty($request['request_id']);
        });

        // last_seen_at should be touched by a successful HTTP-direct call
        $this->assertNotNull($bridge->fresh()->last_seen_at);
    }

    public function test_mcp_call_skips_authorization_header_when_bridge_has_no_secret(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'no-secret-session',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'http://localhost:7531',
            'endpoint_secret' => null,
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'http://localhost:7531/mcp/harbormaster' => Http::response(['result' => ['content' => []]], 200),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/list',
            'params' => ['cursor' => null],
        ]);

        $response->assertOk();

        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_mcp_call_returns_502_when_http_mode_bridge_responds_non_2xx(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'broken-bridge',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://broken.example',
            'endpoint_secret' => 'secret',
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://broken.example/mcp/harbormaster' => Http::response('Internal Server Error', 500),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/list',
            'params' => ['cursor' => null],
        ]);

        $response->assertStatus(502)
            ->assertJsonStructure(['error']);
    }

    public function test_mcp_call_returns_502_when_http_mode_bridge_unreachable(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'unreachable',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://unreachable.example',
            'endpoint_secret' => null,
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://unreachable.example/mcp/harbormaster' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('connect timeout');
            },
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/list',
            'params' => ['cursor' => null],
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('error', fn ($v) => str_contains($v, 'connect timeout'));
    }
}
