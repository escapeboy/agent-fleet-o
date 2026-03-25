<?php

namespace App\Domain\Bridge\Actions;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use Illuminate\Support\Facades\Redis;

class RegisterBridgeConnection
{
    public function execute(
        string $teamId,
        string $sessionId,
        ?string $bridgeVersion,
        array $endpoints,
        ?string $ipAddress = null,
        ?string $label = null,
    ): BridgeConnection {
        // Check for endpoints pre-cached by the relay (which calls /bridge/endpoints
        // before the daemon calls /bridge/register — a timing quirk in the relay binary).
        $pendingKey = "bridge:pending_endpoints:{$teamId}";
        $pending = Redis::connection('bridge')->get($pendingKey);
        if ($pending) {
            $endpoints = json_decode($pending, true) ?: $endpoints;
            Redis::connection('bridge')->del($pendingKey);
        }

        // If endpoints are empty, try to carry over from the most recent disconnected
        // connection so that previously-discovered agents are preserved across restarts.
        if (empty($endpoints)) {
            $previous = BridgeConnection::where('team_id', $teamId)
                ->where('status', BridgeConnectionStatus::Disconnected->value)
                ->orderByDesc('connected_at')
                ->value('endpoints');

            if (! empty($previous)) {
                $endpoints = $previous;
            }
        }

        // Upsert: find existing connection by session_id first, then fall back to
        // the most recent connection for this team. The relay binary generates a new
        // session_id on each reconnect (relay-{team_id}-{timestamp}), so matching
        // only by session_id would create duplicate records on every reconnect.
        $existing = BridgeConnection::where('team_id', $teamId)
            ->where('session_id', $sessionId)
            ->first();

        if (! $existing) {
            $existing = BridgeConnection::where('team_id', $teamId)
                ->orderByDesc('connected_at')
                ->first();
        }

        if ($existing) {
            $existing->update([
                'session_id' => $sessionId,
                'status' => BridgeConnectionStatus::Connected,
                'bridge_version' => $bridgeVersion,
                'endpoints' => ! empty($endpoints) ? $endpoints : $existing->endpoints,
                'ip_address' => $ipAddress,
                'label' => $label ?? $existing->label,
                'connected_at' => now(),
                'last_seen_at' => now(),
                'disconnected_at' => null,
                // Relay WebSocket connections are NOT HTTP-mode — clear any stale endpoint_url
                // inherited from a previous HTTP tunnel session (e.g. Cloudflare Tunnel).
                'endpoint_url' => null,
                'endpoint_secret' => null,
            ]);

            return $existing->fresh();
        }

        return BridgeConnection::create([
            'team_id' => $teamId,
            'session_id' => $sessionId,
            'label' => $label,
            'status' => BridgeConnectionStatus::Connected,
            'bridge_version' => $bridgeVersion,
            'endpoints' => $endpoints,
            'ip_address' => $ipAddress,
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
