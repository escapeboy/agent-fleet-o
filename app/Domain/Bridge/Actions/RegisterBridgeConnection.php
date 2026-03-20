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

        // Upsert: if a connection with the same session_id already exists, reconnect it.
        // This handles relay restarts and daemon re-registrations without creating duplicates.
        $existing = BridgeConnection::where('team_id', $teamId)
            ->where('session_id', $sessionId)
            ->first();

        if ($existing) {
            $existing->update([
                'status' => BridgeConnectionStatus::Connected,
                'bridge_version' => $bridgeVersion,
                'endpoints' => ! empty($endpoints) ? $endpoints : $existing->endpoints,
                'ip_address' => $ipAddress,
                'label' => $label ?? $existing->label,
                'connected_at' => now(),
                'last_seen_at' => now(),
                'disconnected_at' => null,
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
