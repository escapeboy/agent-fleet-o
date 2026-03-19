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

        $connection = $teamId
            ? BridgeConnection::where('team_id', $teamId)
                ->active()
                ->orderByDesc('connected_at')
                ->first()
            : null;

        if (! $connection) {
            return Response::text(json_encode([
                'connected' => false,
                'message' => 'No active FleetQ Bridge connection found.',
            ]));
        }

        $result = ['connected' => true, 'bridge_version' => $connection->bridge_version];

        if ($type === 'all' || $type === 'llm') {
            $result['llm_endpoints'] = $connection->llmEndpoints();
        }

        if ($type === 'all' || $type === 'agent') {
            $result['agents'] = $connection->agents();
        }

        if ($type === 'all' || $type === 'mcp_server') {
            $result['mcp_servers'] = $connection->mcpServers();
        }

        return Response::text(json_encode($result));
    }
}
