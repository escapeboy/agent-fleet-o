<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Bridge\Actions\RegisterBridgeConnection;
use App\Domain\Bridge\Actions\TerminateBridgeConnection;
use App\Domain\Bridge\Actions\UpdateBridgeEndpoints;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Bridge
 */
class BridgeController extends Controller
{
    /**
     * Get the current team's bridge connection status.
     *
     * @response 200 {"data": {"connected": true, "status": "connected", "bridge_version": "1.0.0", "llm_count": 2, "agent_count": 3}}
     */
    public function status(Request $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $connection = BridgeConnection::where('team_id', $teamId)
            ->orderByDesc('connected_at')
            ->first();

        if (! $connection) {
            return response()->json(['data' => [
                'connected' => false,
                'message' => 'No FleetQ Bridge connection found.',
            ]]);
        }

        return response()->json(['data' => [
            'connected' => $connection->isActive(),
            'status' => $connection->status->value,
            'bridge_version' => $connection->bridge_version,
            'session_id' => $connection->session_id,
            'connected_at' => $connection->connected_at?->toISOString(),
            'last_seen_at' => $connection->last_seen_at?->toISOString(),
            'uptime' => $connection->connected_at ? now()->diffForHumans($connection->connected_at, true) : null,
            'llm_count' => $connection->onlineLlmCount(),
            'agent_count' => $connection->foundAgentCount(),
            'llm_endpoints' => $connection->llmEndpoints(),
            'agents' => $connection->agents(),
            'mcp_servers' => $connection->mcpServers(),
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
        ]);

        $teamId = $request->user()->current_team_id;

        $connection = app(RegisterBridgeConnection::class)->execute(
            teamId: $teamId,
            sessionId: $validated['session_id'],
            bridgeVersion: $validated['bridge_version'] ?? null,
            endpoints: $validated['endpoints'] ?? [],
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => [
            'session_id' => $connection->session_id,
            'connected_at' => $connection->connected_at->toISOString(),
        ]], 201);
    }

    /**
     * Update the endpoints manifest (called by the bridge daemon on discovery).
     */
    public function updateEndpoints(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:255',
            'endpoints' => 'required|array',
        ]);

        $teamId = $request->user()->current_team_id;

        $connection = BridgeConnection::where('team_id', $teamId)
            ->where('session_id', $validated['session_id'])
            ->active()
            ->first();

        if (! $connection) {
            return response()->json(['error' => 'No active bridge session found.'], 404);
        }

        app(UpdateBridgeEndpoints::class)->execute($connection, $validated['endpoints']);

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

    /**
     * Disconnect the bridge session.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $connection = BridgeConnection::where('team_id', $teamId)
            ->active()
            ->orderByDesc('connected_at')
            ->first();

        if (! $connection) {
            return response()->json(['error' => 'No active bridge connection.'], 404);
        }

        app(TerminateBridgeConnection::class)->execute($connection);

        return response()->json(['data' => ['disconnected' => true]]);
    }
}
