<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Services\BridgeRouter;
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

    protected string $description = 'Get the FleetQ Bridge connection status for the current team. Shows all connections with aggregated agents, LLMs, and MCP servers. Supports multi-bridge setups.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        $connections = $teamId
            ? app(BridgeRouter::class)->allConnections($teamId)
            : collect();

        if ($connections->isEmpty()) {
            return Response::text(json_encode([
                'connected' => false,
                'connection_count' => 0,
                'message' => 'No FleetQ Bridge connection found. Download and start the bridge daemon.',
            ]));
        }

        $primary = $connections->first(fn (BridgeConnection $c) => $c->isActive());
        $activeCount = $connections->filter(fn (BridgeConnection $c) => $c->isActive())->count();

        $allAgents = $connections->filter(fn ($c) => $c->isActive())
            ->flatMap(fn ($c) => $c->agents())
            ->filter(fn ($a) => $a['found'] ?? false)
            ->unique('key')
            ->values();

        return Response::text(json_encode([
            'connected' => $primary !== null,
            'connection_count' => $connections->count(),
            'active_count' => $activeCount,
            // Primary connection details (backward compat)
            'bridge_version' => $primary?->bridge_version,
            'connected_at' => $primary?->connected_at?->toISOString(),
            'last_seen_at' => $primary?->last_seen_at?->toISOString(),
            'uptime' => $primary?->connected_at ? now()->diffForHumans($primary->connected_at, true) : null,
            // Aggregated across all active connections
            'agents' => $allAgents,
            'agent_count' => $allAgents->count(),
            'llm_endpoints' => $primary?->llmEndpoints() ?? [],
            'mcp_servers' => $primary?->mcpServers() ?? [],
            // All connections summary
            'connections' => $connections->map(fn (BridgeConnection $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'status' => $c->status->value,
                'mode' => $c->isHttpMode() ? 'http' : 'relay',
                'endpoint_url' => $c->endpoint_url,
                'tunnel_provider' => $c->tunnel_provider,
                'bridge_version' => $c->bridge_version,
                'ip_address' => $c->ip_address,
                'connected_at' => $c->connected_at?->toISOString(),
                'agent_count' => $c->foundAgentCount(),
                'llm_count' => $c->onlineLlmCount(),
            ])->values(),
        ]));
    }
}
