<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Bridge\Actions\RegisterBridgeConnection;
use App\Domain\Bridge\Actions\TerminateBridgeConnection;
use App\Domain\Bridge\Actions\UpdateBridgeEndpoints;
use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Services\BridgeRouter;
use App\Http\Controllers\Controller;
use App\Infrastructure\Bridge\BridgeRequestRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * @tags Bridge
 */
class BridgeController extends Controller
{
    /**
     * Resolve the team ID from the Sanctum token's abilities (preferred)
     * or fall back to the user's current_team_id.
     *
     * Prevents wrong-team registration when the user switches teams in the
     * web UI but the API token is scoped to a specific team.
     */
    protected function resolveTeamId(Request $request): string
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'getAbilities')) {
            foreach ($token->getAbilities() as $ability) {
                if (str_starts_with($ability, 'team:') && strlen($ability) > 5) {
                    return substr($ability, 5);
                }
            }
        }

        return $user->current_team_id;
    }
    /**
     * Get the current team's bridge connection status.
     *
     * Returns all connections (active + recent) with backward-compatible single-bridge fields.
     *
     * @response 200 {"data": {"connected": true, "connections": [...]}}
     */
    public function status(Request $request): JsonResponse
    {
        $teamId = $this->resolveTeamId($request);

        $connections = app(BridgeRouter::class)->allConnections($teamId);

        if ($connections->isEmpty()) {
            return response()->json(['data' => [
                'connected' => false,
                'connections' => [],
                'message' => 'No FleetQ Bridge connection found.',
            ]]);
        }

        $primary = $connections->firstWhere('status', 'connected');

        return response()->json(['data' => [
            // Backward compat: single-bridge fields from the best active connection
            'connected' => $primary !== null,
            'status' => $primary?->status->value ?? $connections->first()->status->value,
            'bridge_version' => $primary?->bridge_version,
            'session_id' => $primary?->session_id,
            'connected_at' => $primary?->connected_at?->toISOString(),
            'last_seen_at' => $primary?->last_seen_at?->toISOString(),
            'uptime' => $primary?->connected_at ? now()->diffForHumans($primary->connected_at, true) : null,
            'llm_count' => $primary?->onlineLlmCount() ?? 0,
            'agent_count' => $primary?->foundAgentCount() ?? 0,
            'llm_endpoints' => $primary?->llmEndpoints() ?? [],
            'agents' => $primary?->agents() ?? [],
            'mcp_servers' => $primary?->mcpServers() ?? [],
            // Multi-bridge: all connections
            'connections' => $connections->map(fn (BridgeConnection $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'status' => $c->status->value,
                'bridge_version' => $c->bridge_version,
                'ip_address' => $c->ip_address,
                'priority' => $c->priority,
                'connected_at' => $c->connected_at?->toISOString(),
                'last_seen_at' => $c->last_seen_at?->toISOString(),
                'agents' => $c->agents(),
                'llm_endpoints' => $c->llmEndpoints(),
                'mcp_servers' => $c->mcpServers(),
            ])->values(),
            'connected_count' => $connections->filter(fn ($c) => $c->isActive())->count(),
        ]]);
    }

    /**
     * Register or refresh a bridge connection (called by the bridge daemon on connect).
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:255',
            'bridge_version' => 'nullable|string|max:50',
            'endpoints' => 'nullable|array',
            'label' => 'nullable|string|max:100',
        ]);

        $teamId = $this->resolveTeamId($request);

        $connection = app(RegisterBridgeConnection::class)->execute(
            teamId: $teamId,
            sessionId: $validated['session_id'],
            bridgeVersion: $validated['bridge_version'] ?? null,
            endpoints: $validated['endpoints'] ?? [],
            ipAddress: $request->ip(),
            label: $validated['label'] ?? null,
        );

        return response()->json(['data' => [
            'session_id' => $connection->session_id,
            'team_id' => $teamId,
            'connected_at' => $connection->connected_at->toISOString(),
            'reverb' => [
                'app_key' => config('reverb.apps.apps.0.key'),
                'relay_url' => $this->buildReverbUrl(),
            ],
        ]], 201);
    }

    /**
     * Update the endpoints manifest (called by the bridge daemon on discovery).
     */
    public function updateEndpoints(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'nullable|string|max:255',
            'endpoints' => 'nullable|array',
        ]);

        $teamId = $this->resolveTeamId($request);
        $endpoints = $validated['endpoints'] ?? [];

        // Primary: find the active connection (prefer matching session_id)
        $query = BridgeConnection::where('team_id', $teamId)->active();

        if (! empty($validated['session_id'])) {
            $connection = (clone $query)->where('session_id', $validated['session_id'])->first()
                ?? $query->orderByDesc('connected_at')->first();
        } else {
            $connection = $query->orderByDesc('connected_at')->first();
        }

        // Fallback: check the most-recent connection within the last 30 seconds regardless of status.
        if (! $connection) {
            $connection = BridgeConnection::where('team_id', $teamId)
                ->where('connected_at', '>=', now()->subSeconds(30))
                ->orderByDesc('connected_at')
                ->first();
        }

        if ($connection) {
            app(UpdateBridgeEndpoints::class)->execute($connection, $endpoints);
        } else {
            // The relay calls endpoints before the daemon calls register — cache in Redis
            // so register can pick it up and apply it immediately on connection creation.
            Redis::connection('bridge')->setex(
                "bridge:pending_endpoints:{$teamId}",
                60,
                json_encode($endpoints),
            );
        }

        return response()->json(['data' => ['updated' => true]]);
    }

    /**
     * Heartbeat — keeps the connection alive (called by the bridge daemon periodically).
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:255',
        ]);

        $teamId = $this->resolveTeamId($request);

        $connection = BridgeConnection::where('team_id', $teamId)
            ->where('session_id', $validated['session_id'])
            ->active()
            ->first();

        if (! $connection) {
            return response()->json(['error' => 'Session not found.'], 404);
        }

        $connection->update(['last_seen_at' => now()]);

        return response()->json(['data' => ['alive' => true]]);
    }

    /**
     * Authenticate a Pusher/Reverb private channel subscription.
     *
     * Generates the HMAC-SHA256 auth signature manually instead of using
     * Broadcast::auth() which has guard resolution issues in API context.
     */
    public function broadcastingAuth(Request $request): JsonResponse
    {
        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');
        $user = $request->user();

        if (! $socketId || ! $channelName || ! $user) {
            return response()->json(['error' => 'Missing parameters'], 400);
        }

        // Authorize: only allow daemon.{teamId} channels matching user's team
        if (preg_match('/^private-daemon\.(.+)$/', $channelName, $m)) {
            if ($this->resolveTeamId($request) !== $m[1]) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
        } else {
            return response()->json(['error' => 'Unsupported channel'], 403);
        }

        // Generate Pusher auth signature: HMAC-SHA256(secret, socket_id:channel_name)
        $secret = config('reverb.apps.apps.0.secret');
        $key = config('reverb.apps.apps.0.key');
        $signature = hash_hmac('sha256', "{$socketId}:{$channelName}", $secret);

        return response()->json([
            'auth' => "{$key}:{$signature}",
        ]);
    }

    private function buildReverbUrl(): string
    {
        // Use VITE_REVERB_* (public-facing) when available, otherwise fall back to the
        // app config. Server-side REVERB_HOST may point to an internal Docker host.
        $scheme = config('app.reverb_public_scheme')
            ?: config('reverb.apps.apps.0.options.scheme', 'https');
        $host = config('app.reverb_public_host')
            ?: config('reverb.apps.apps.0.options.host');
        $port = config('app.reverb_public_port')
            ?: config('reverb.apps.apps.0.options.port', 443);
        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';

        return "{$wsScheme}://{$host}:{$port}";
    }

    /**
     * Proxy an MCP tool call through the relay to a bridge-hosted MCP server.
     *
     * Uses the same Redis frame-based protocol as all bridge communication:
     * RPUSH to bridge:req:{teamId} → relay binary → WebSocket → bridge daemon → MCP server.
     *
     * @response 200 {"result": {"content": [{"type": "text", "text": "..."}]}}
     */
    public function mcpCall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'server' => 'required|string|max:100',
            'method' => 'required|string|in:tools/call,tools/list',
            'params' => 'required|array',
            'timeout' => 'nullable|integer|min:1|max:300',
        ]);

        $teamId = $this->resolveTeamId($request);
        $serverName = $validated['server'];
        $timeout = $validated['timeout'] ?? 60;
        $requestId = Str::uuid()->toString();

        $bridge = app(BridgeRouter::class)->resolveForMcpServer($teamId, $serverName);

        if (! $bridge) {
            return response()->json([
                'error' => "No active bridge with MCP server '{$serverName}'.",
            ], 404);
        }

        Log::info('BridgeController: proxying MCP call', [
            'request_id' => $requestId,
            'team_id' => $teamId,
            'bridge_id' => $bridge->id,
            'server' => $serverName,
            'method' => $validated['method'],
            'tool' => $validated['params']['name'] ?? null,
        ]);

        try {
            $registry = app(BridgeRequestRegistry::class);
            $registry->register($requestId, $teamId);

            Redis::connection('bridge')->rpush(
                "bridge:req:{$teamId}",
                json_encode([
                    'request_id' => $requestId,
                    'frame_type' => 0x0020, // FrameMcpToolCall
                    'payload' => [
                        'request_id' => $requestId,
                        'server' => $serverName,
                        'method' => $validated['method'],
                        'params' => $validated['params'],
                        'timeout' => $timeout,
                    ],
                ]),
            );

            $item = $registry->popChunk($requestId, $timeout + 10);

            if ($item === null) {
                return response()->json([
                    'error' => "MCP call timed out after {$timeout}s",
                ], 504);
            }

            $usage = $registry->getUsage($requestId);
            if (isset($usage['__error'])) {
                return response()->json([
                    'error' => $usage['__error'],
                ], 502);
            }

            // Return the raw MCP result
            $result = json_decode($item['chunk'] ?? '', true);

            return response()->json($result ?: ['result' => ['content' => [['type' => 'text', 'text' => $item['chunk'] ?? '']]]]);
        } catch (\Throwable $e) {
            Log::error('BridgeController: MCP call exception', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => "Bridge MCP call failed: {$e->getMessage()}",
            ], 502);
        }
    }

    /**
     * Connect a bridge via HTTP tunnel URL (HTTP mode).
     *
     * The customer pastes their tunnel URL (Cloudflare Tunnel, Tailscale Funnel, ngrok, etc.).
     * FleetQ calls /discover to validate and fetch the available agents/LLMs.
     *
     * @response 201 {"data": {"id": "...", "endpoint_url": "...", "agents": [...]}}
     */
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint_url' => 'required|url|max:500',
            'endpoint_secret' => 'nullable|string|max:255',
            'tunnel_provider' => 'nullable|string|in:cloudflare,tailscale,ngrok,custom|max:50',
            'label' => 'nullable|string|max:100',
        ]);

        $teamId = $this->resolveTeamId($request);
        $endpointUrl = rtrim($validated['endpoint_url'], '/');

        // Validate URL by calling /discover on the bridge server
        $discoverUrl = $endpointUrl.'/discover';
        $headers = [];

        if (! empty($validated['endpoint_secret'])) {
            $headers['Authorization'] = 'Bearer '.$validated['endpoint_secret'];
        }

        try {
            $discoverResponse = Http::timeout(10)
                ->withHeaders($headers)
                ->get($discoverUrl);

            if (! $discoverResponse->successful()) {
                return response()->json([
                    'error' => "Bridge server responded with HTTP {$discoverResponse->status()}. "
                        .'Ensure your local bridge server is running and the tunnel is active.',
                ], 422);
            }

            $discovered = $discoverResponse->json();
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Could not reach the bridge server at the provided URL: '.$e->getMessage(),
            ], 422);
        }

        $endpoints = [
            'agents' => $discovered['agents'] ?? [],
            'llm_endpoints' => $discovered['llm_endpoints'] ?? [],
            'mcp_servers' => $discovered['mcp_servers'] ?? [],
        ];

        $connection = BridgeConnection::updateOrCreate(
            ['team_id' => $teamId, 'endpoint_url' => $endpointUrl],
            [
                'status' => BridgeConnectionStatus::Connected,
                'endpoint_secret' => $validated['endpoint_secret'] ?? null,
                'tunnel_provider' => $validated['tunnel_provider'] ?? 'custom',
                'label' => $validated['label'] ?? null,
                'endpoints' => $endpoints,
                'connected_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['data' => [
            'id' => $connection->id,
            'endpoint_url' => $connection->endpoint_url,
            'tunnel_provider' => $connection->tunnel_provider,
            'label' => $connection->label,
            'status' => $connection->status->value,
            'agents' => $connection->agents(),
            'llm_endpoints' => $connection->llmEndpoints(),
            'mcp_servers' => $connection->mcpServers(),
            'connected_at' => $connection->connected_at->toISOString(),
        ]], 201);
    }

    /**
     * Update the endpoint URL for an HTTP-mode bridge connection.
     *
     * Use when the tunnel URL changes (e.g., Cloudflare quick tunnels regenerate on restart).
     */
    public function updateUrl(Request $request, BridgeConnection $connection): JsonResponse
    {
        $teamId = $this->resolveTeamId($request);

        if ($connection->team_id !== $teamId) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        $validated = $request->validate([
            'endpoint_url' => 'required|url|max:500',
            'endpoint_secret' => 'nullable|string|max:255',
        ]);

        $connection->update([
            'endpoint_url' => rtrim($validated['endpoint_url'], '/'),
            'endpoint_secret' => $validated['endpoint_secret'] ?? $connection->endpoint_secret,
            'last_seen_at' => now(),
        ]);

        return response()->json(['data' => [
            'id' => $connection->id,
            'endpoint_url' => $connection->endpoint_url,
            'updated' => true,
        ]]);
    }

    /**
     * Ping an HTTP-mode bridge connection to verify it is reachable.
     *
     * Calls GET /health on the bridge server and updates last_seen_at / status.
     */
    public function ping(Request $request, BridgeConnection $connection): JsonResponse
    {
        $teamId = $this->resolveTeamId($request);

        if ($connection->team_id !== $teamId) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        if (! $connection->isHttpMode()) {
            return response()->json(['error' => 'Ping is only available for HTTP-mode connections.'], 422);
        }

        $headers = [];

        if (! empty($connection->endpoint_secret)) {
            $headers['Authorization'] = 'Bearer '.$connection->endpoint_secret;
        }

        try {
            $healthResponse = Http::timeout(10)
                ->withHeaders($headers)
                ->get(rtrim($connection->endpoint_url, '/').'/health');

            $online = $healthResponse->successful()
                && ($healthResponse->json('status') === 'ok' || $healthResponse->successful());

            $connection->update([
                'status' => $online ? BridgeConnectionStatus::Connected : BridgeConnectionStatus::Disconnected,
                'last_seen_at' => now(),
            ]);

            return response()->json(['data' => [
                'online' => $online,
                'status' => $connection->status->value,
                'last_seen_at' => $connection->last_seen_at->toISOString(),
            ]]);
        } catch (\Throwable $e) {
            $connection->update(['status' => BridgeConnectionStatus::Disconnected]);

            return response()->json(['data' => [
                'online' => false,
                'status' => BridgeConnectionStatus::Disconnected->value,
                'error' => $e->getMessage(),
            ]]);
        }
    }

    /**
     * Disconnect a bridge session.
     *
     * Accepts optional session_id (relay-generated, used to guard against reconnect races)
     * or connection_id to disconnect a specific bridge.
     * Without either, disconnects all active bridges for the team.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'nullable|string|max:255',
            'connection_id' => 'nullable|uuid',
        ]);

        $teamId = $this->resolveTeamId($request);
        $sessionId = $validated['session_id'] ?? null;
        $connectionId = $validated['connection_id'] ?? null;

        // If session_id is provided (relay disconnect), only disconnect if it still
        // matches the active connection. Guards against the reconnect race where the
        // old relay goroutine fires DELETE after a new session has already registered.
        if ($sessionId) {
            $connection = BridgeConnection::where('team_id', $teamId)
                ->where('session_id', $sessionId)
                ->active()
                ->first();

            if (! $connection) {
                // Either already superseded by a newer session or already disconnected.
                Log::info('BridgeController: stale disconnect ignored', [
                    'team_id' => $teamId,
                    'session_id' => $sessionId,
                ]);

                return response()->json(['data' => ['disconnected' => 0, 'reason' => 'stale']]);
            }

            app(TerminateBridgeConnection::class)->execute($connection);

            return response()->json(['data' => ['disconnected' => 1]]);
        }

        $query = BridgeConnection::where('team_id', $teamId)->active();

        if ($connectionId) {
            $query->where('id', $connectionId);
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            return response()->json(['error' => 'No active bridge connection.'], 404);
        }

        $connections->each(fn ($c) => app(TerminateBridgeConnection::class)->execute($c));

        return response()->json(['data' => ['disconnected' => $connections->count()]]);
    }
}
