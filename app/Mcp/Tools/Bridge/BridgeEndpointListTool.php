<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class BridgeEndpointListTool extends Tool
{
    protected string $name = 'bridge_endpoint_list';

    protected string $description = 'List all endpoints (LLMs, AI agents, MCP servers) discovered by the connected FleetQ Bridge daemon.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('Filter by endpoint type. Defaults to all.')
                ->enum(['llm', 'agent', 'mcp_server', 'all']),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $type = $request->input('type', 'all');

        $connections = $teamId
            ? BridgeConnection::where('team_id', $teamId)
                ->active()
                ->orderByDesc('priority')
                ->orderByDesc('connected_at')
                ->get()
            : collect();

        if ($connections->isEmpty()) {
            return Response::text(json_encode([
                'connected' => false,
                'message' => 'No active FleetQ Bridge connection found.',
            ]));
        }

        $result = [
            'connected' => true,
            'connection_count' => $connections->count(),
        ];

        if ($type === 'all' || $type === 'llm') {
            $result['llm_endpoints'] = $connections
                ->flatMap(fn ($c) => collect($c->llmEndpoints())->map(fn ($e) => array_merge($e, ['bridge_id' => $c->id, 'bridge_label' => $c->label])))
                ->values()->all();
        }

        if ($type === 'all' || $type === 'agent') {
            $result['agents'] = $connections
                ->flatMap(fn ($c) => collect($c->agents())->map(fn ($a) => array_merge($a, ['bridge_id' => $c->id, 'bridge_label' => $c->label])))
                ->values()->all();
        }

        if ($type === 'all' || $type === 'mcp_server') {
            $result['mcp_servers'] = $connections
                ->flatMap(fn ($c) => collect($c->mcpServers())->map(fn ($s) => array_merge($s, ['bridge_id' => $c->id, 'bridge_label' => $c->label])))
                ->values()->all();
        }

        return Response::text(json_encode($result));
    }
}
