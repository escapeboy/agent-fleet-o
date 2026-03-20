<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class BridgeListTool extends Tool
{
    protected string $name = 'bridge_list';

    protected string $description = 'List all bridge connections for the current team (active and recent disconnected). Shows each bridge with its label, status, IP, agents, and LLM endpoints.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status. Defaults to all.')
                ->enum(['connected', 'disconnected', 'all']),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $status = $request->input('status', 'all');

        $query = BridgeConnection::where('team_id', $teamId)
            ->orderByDesc('priority')
            ->orderByDesc('connected_at');

        if ($status === 'connected') {
            $query->active();
        } elseif ($status === 'disconnected') {
            $query->where('status', 'disconnected');
        }

        $connections = $query->limit(20)->get();

        // Get current routing preferences
        $team = Team::find($teamId);
        $bridgeSettings = $team?->settings['bridge'] ?? [];

        return Response::text(json_encode([
            'connections' => $connections->map(fn (BridgeConnection $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'status' => $c->status->value,
                'bridge_version' => $c->bridge_version,
                'ip_address' => $c->ip_address,
                'priority' => $c->priority,
                'connected_at' => $c->connected_at?->toISOString(),
                'last_seen_at' => $c->last_seen_at?->toISOString(),
                'disconnected_at' => $c->disconnected_at?->toISOString(),
                'agents' => collect($c->agents())->filter(fn ($a) => $a['found'] ?? false)->values(),
                'llm_count' => $c->onlineLlmCount(),
                'mcp_server_count' => count($c->mcpServers()),
            ])->values(),
            'total' => $connections->count(),
            'active' => $connections->filter(fn ($c) => $c->isActive())->count(),
            'routing' => [
                'mode' => $bridgeSettings['routing_mode'] ?? 'auto',
                'preferred_bridge_id' => $bridgeSettings['preferred_bridge_id'] ?? null,
                'agent_routing' => $bridgeSettings['agent_routing'] ?? [],
            ],
        ]));
    }
}
