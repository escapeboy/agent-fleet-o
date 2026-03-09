<?php

namespace App\Infrastructure\AI\Gateways;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use RuntimeException;

class LocalBridgeGateway implements AiGatewayInterface
{
    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $connection = $this->requireActiveConnection($request->teamId);

        return $this->routeRequest($connection, $request);
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        $connection = $this->requireActiveConnection($request->teamId);

        return $this->routeRequest($connection, $request, $onChunk);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0; // Bridge requests are always zero cost
    }

    private function requireActiveConnection(?string $teamId): BridgeConnection
    {
        if (! $teamId) {
            throw new RuntimeException(
                'FleetQ Bridge: No team context available. Ensure the request includes a team ID.'
            );
        }

        $connection = BridgeConnection::where('team_id', $teamId)
            ->active()
            ->orderByDesc('connected_at')
            ->first();

        if (! $connection) {
            throw new RuntimeException(
                'FleetQ Bridge is not connected. '
                .'Download and start the bridge daemon: https://github.com/fleetq/fleetq-bridge'
            );
        }

        return $connection;
    }

    private function routeRequest(BridgeConnection $connection, AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        // TODO: Route the request through the WebSocket relay to the connected bridge.
        // This requires the relay WebSocket server (Laravel Reverb or equivalent) to be running.
        // The relay sends an LLM_REQUEST or AGENT_REQUEST frame to the bridge daemon,
        // which forwards it to the local LLM/agent and streams the response back.
        //
        // For now, throw a descriptive error until the relay is implemented.
        throw new RuntimeException(
            'FleetQ Bridge relay not yet configured. '
            .'The bridge is connected but the relay server needs to be started. '
            .'Bridge version: '.($connection->bridge_version ?? 'unknown')
        );
    }
}
