<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Models\BridgeConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class BridgeStatusTool extends Tool
{
    protected string $name = 'bridge_status';

    protected string $description = 'Get the FleetQ Bridge connection status for the current team, including discovered local LLMs, agents, and MCP servers.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        $connection = $teamId
            ? BridgeConnection::where('team_id', $teamId)
                ->orderByDesc('connected_at')
                ->first()
            : null;

        if (! $connection) {
            return Response::text(json_encode([
                'connected' => false,
                'message' => 'No FleetQ Bridge connection found. Download and start the bridge daemon.',
                'download_url' => 'https://github.com/fleetq/fleetq-bridge/releases',
            ]));
        }

        return Response::text(json_encode([
            'connected' => $connection->isActive(),
            'status' => $connection->status->value,
            'bridge_version' => $connection->bridge_version,
            'session_id' => $connection->session_id,
            'connected_at' => $connection->connected_at?->toISOString(),
            'last_seen_at' => $connection->last_seen_at?->toISOString(),
            'uptime' => $connection->connected_at ? now()->diffForHumans($connection->connected_at, true) : null,
            'llm_endpoints' => $connection->llmEndpoints(),
            'agents' => $connection->agents(),
            'mcp_servers' => $connection->mcpServers(),
            'llm_count' => $connection->onlineLlmCount(),
            'agent_count' => $connection->foundAgentCount(),
        ]));
    }
}
