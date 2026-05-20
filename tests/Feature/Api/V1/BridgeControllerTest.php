<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_mcp_call_returns_502_when_http_mode_bridge_responds_5xx(): void
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

    public function test_mcp_call_passes_4xx_through_from_http_mode_bridge(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'http-bridge-4xx',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://daemon.example',
            'endpoint_secret' => 'secret',
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://daemon.example/mcp/harbormaster' => Http::response(
                ['detail' => "tool not found: 'i_do_not_exist'"],
                404,
            ),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/call',
            'params' => ['name' => 'i_do_not_exist', 'arguments' => []],
        ]);

        // 4xx from the daemon must reach the caller unchanged — neither the
        // status nor the body is wrapped in a generic 502.
        $response->assertStatus(404)
            ->assertExactJson(['detail' => "tool not found: 'i_do_not_exist'"]);
    }

    public function test_mcp_call_passes_4xx_through_with_non_json_body(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'http-bridge-4xx-text',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://daemon.example',
            'endpoint_secret' => null,
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://daemon.example/mcp/harbormaster' => Http::response('Bad Request', 400),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/call',
            'params' => ['name' => 'whatever', 'arguments' => []],
        ]);

        // Non-JSON 4xx body falls back to the wrapper shape but keeps the
        // original status code so callers can distinguish bad-request from
        // gateway-error semantics.
        $response->assertStatus(400)
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
                throw new ConnectionException('connect timeout');
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

    // -----------------------------------------------------------------------
    // POST /api/v1/bridge/mcp/call — stream=true (SSE forwarding)
    // -----------------------------------------------------------------------

    public function test_mcp_call_stream_true_forwards_sse_content_type(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'http-stream-bridge',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://daemon.example',
            'endpoint_secret' => 'secret',
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Two concatenated SSE events: a heartbeat + a final result.
        $sseBody = "event: heartbeat\n"
            ."data: {\"elapsed_ms\":50}\n\n"
            ."event: result\n"
            ."data: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"hello\"}]}}\n\n";

        Http::fake([
            'https://daemon.example/mcp/harbormaster' => Http::response(
                $sseBody,
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/call',
            'params' => ['name' => 'list_hosts', 'arguments' => []],
            'stream' => true,
        ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith(
            'text/event-stream',
            $response->headers->get('Content-Type'),
        );
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        // Laravel's middleware may append `, private` to Cache-Control;
        // we only care that no-cache made it through so reverse proxies
        // don't cache the SSE stream.
        $this->assertStringContainsString(
            'no-cache',
            (string) $response->headers->get('Cache-Control'),
        );

        // Body forwarding is verified end-to-end by the harbormaster
        // streaming smoke (PR-side, not here). Re-invoking the callback
        // from the test would consume an already-exhausted PSR stream
        // (Http::fake creates a one-shot in-memory body), and Laravel
        // 13's TestResponse::streamedContent() collides with our buffer
        // handling under PHPUnit. Header + status + outgoing-request
        // assertions in the other tests are sufficient unit coverage
        // for this controller's responsibilities; the live wire shape
        // is covered by harbormaster's `smoke-fleetq` CI job.
        $this->assertNotNull($response->baseResponse->getCallback());
    }

    public function test_mcp_call_stream_true_sends_accept_event_stream_to_daemon(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'http-stream-accept',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://daemon.example',
            'endpoint_secret' => 'secret',
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://daemon.example/mcp/harbormaster' => Http::response(
                "event: result\ndata: {\"result\":{\"content\":[]}}\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/list',
            'params' => ['cursor' => null],
            'stream' => true,
        ])->assertStatus(200);

        // Verify the outgoing request actually asked for an SSE stream
        // and carried the bridge's bearer token.
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://daemon.example/mcp/harbormaster'
                && $request->header('Accept')[0] === 'text/event-stream'
                && $request->header('Authorization')[0] === 'Bearer secret';
        });
    }

    public function test_mcp_call_stream_true_passes_4xx_through_as_json(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'http-stream-4xx',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://daemon.example',
            'endpoint_secret' => 'secret',
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://daemon.example/mcp/harbormaster' => Http::response(
                ['detail' => "tool not found: 'nope'"],
                404,
            ),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/call',
            'params' => ['name' => 'nope', 'arguments' => []],
            'stream' => true,
        ]);

        // Pre-stream 4xx → JSON pass-through, NOT SSE. Same shape as the
        // synchronous path so callers don't need a different error reader
        // for stream=true vs stream=false.
        $response->assertStatus(404)
            ->assertExactJson(['detail' => "tool not found: 'nope'"]);
    }

    public function test_mcp_call_stream_true_returns_502_on_5xx(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'http-stream-5xx',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://daemon.example',
            'endpoint_secret' => 'secret',
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://daemon.example/mcp/harbormaster' => Http::response(
                'Internal Server Error',
                500,
            ),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/call',
            'params' => ['name' => 'list_hosts', 'arguments' => []],
            'stream' => true,
        ]);

        $response->assertStatus(502)
            ->assertJsonStructure(['error']);
    }

    public function test_mcp_call_stream_true_rejected_for_relay_mode_bridge(): void
    {
        $this->actingAsApiUser();

        // Relay-mode bridge: no endpoint_url set.
        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-bridge',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => null,
            'endpoint_secret' => null,
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/call',
            'params' => ['name' => 'list_hosts', 'arguments' => []],
            'stream' => true,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath(
                'error',
                fn ($v) => str_contains($v, 'HTTP-tunnel-mode'),
            );
    }

    public function test_mcp_call_stream_false_unchanged_from_sync_path(): void
    {
        $this->actingAsApiUser();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'http-stream-default',
            'status' => BridgeConnectionStatus::Connected,
            'endpoint_url' => 'https://daemon.example',
            'endpoint_secret' => 'secret',
            'endpoints' => [
                'mcp_servers' => [['name' => 'harbormaster']],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://daemon.example/mcp/harbormaster' => Http::response(
                ['result' => ['content' => [['type' => 'text', 'text' => 'sync']]]],
                200,
            ),
        ]);

        // No stream flag → backwards-compat JSON response.
        $response = $this->postJson('/api/v1/bridge/mcp/call', [
            'server' => 'harbormaster',
            'method' => 'tools/call',
            'params' => ['name' => 'list_hosts', 'arguments' => []],
        ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith(
            'application/json',
            $response->headers->get('Content-Type'),
        );

        // Daemon must have been called with Accept: application/json (not SSE).
        Http::assertSent(function ($request): bool {
            return $request->header('Accept')[0] === 'application/json';
        });
    }
}
