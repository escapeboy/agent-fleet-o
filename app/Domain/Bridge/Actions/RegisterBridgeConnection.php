<?php

namespace App\Domain\Bridge\Actions;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;

class RegisterBridgeConnection
{
    public function execute(
        string $teamId,
        string $sessionId,
        ?string $bridgeVersion,
        array $endpoints,
        ?string $ipAddress = null,
    ): BridgeConnection {
        // Disconnect any previous active connections for this team
        BridgeConnection::where('team_id', $teamId)
            ->where('status', BridgeConnectionStatus::Connected->value)
            ->update([
                'status' => BridgeConnectionStatus::Disconnected->value,
                'disconnected_at' => now(),
            ]);

        return BridgeConnection::create([
            'team_id' => $teamId,
            'session_id' => $sessionId,
            'status' => BridgeConnectionStatus::Connected,
            'bridge_version' => $bridgeVersion,
            'endpoints' => $endpoints,
            'ip_address' => $ipAddress,
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
