<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Bridge\Actions\RegisterBridgeConnection;
use App\Domain\Bridge\Actions\TerminateBridgeConnection;
use App\Domain\Bridge\Actions\UpdateBridgeEndpoints;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Services\BridgeRouter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/**
 * @tags Bridge
 */
class BridgeController extends Controller
{
    /**
     * Get the current team's bridge connection status.
     *
     * Returns all connections (active + recent) with backward-compatible single-bridge fields.
     *
     * @response 200 {"data": {"connected": true, "connections": [...]}}
     */
    public function status(Request $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

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

        $teamId = $request->user()->current_team_id;

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

        $teamId = $request->user()->current_team_id;
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

        $teamId = $request->user()->current_team_id;

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

    private function buildReverbUrl(): string
    {
        $scheme = config('reverb.apps.apps.0.options.scheme', 'https');
        $host = config('reverb.apps.apps.0.options.host');
        $port = config('reverb.apps.apps.0.options.port', 443);
        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';

        return "{$wsScheme}://{$host}:{$port}";
    }

    /**
     * Disconnect a bridge session.
     *
     * Accepts optional connection_id to disconnect a specific bridge.
     * Without connection_id, disconnects all active bridges for the team.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'connection_id' => 'nullable|uuid',
        ]);

        $teamId = $request->user()->current_team_id;
        $connectionId = $validated['connection_id'] ?? null;

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
