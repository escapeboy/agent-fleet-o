<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Actions\UpdateBridgeEndpoints;
use App\Domain\Bridge\Models\BridgeConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class BridgeEndpointToggleTool extends Tool
{
    protected string $name = 'bridge_endpoint_toggle';

    protected string $description = 'Enable or disable a specific LLM endpoint or agent discovered by the FleetQ Bridge.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'endpoint_id' => $schema->string()
                ->description('The ID of the endpoint to toggle (from bridge_endpoint_list).')
                ->required(),
            'type' => $schema->string()
                ->description('The endpoint type.')
                ->enum(['llm', 'agent', 'mcp_server'])
                ->required(),
            'enabled' => $schema->boolean()
                ->description('Whether to enable (true) or disable (false) the endpoint.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $endpointId = $request->input('endpoint_id');
        $type = $request->input('type');
        $enabled = (bool) $request->input('enabled', true);

        if (! $endpointId || ! $type) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'endpoint_id and type are required.',
            ]));
        }

        $connection = $teamId
            ? BridgeConnection::where('team_id', $teamId)
                ->active()
                ->orderByDesc('connected_at')
                ->first()
            : null;

        if (! $connection) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'No active FleetQ Bridge connection found.',
            ]));
        }

        $endpoints = $connection->endpoints ?? [];
        $typeKey = match ($type) {
            'llm' => 'llm_endpoints',
            'agent' => 'agents',
            'mcp_server' => 'mcp_servers',
            default => null,
        };

        if (! $typeKey || ! isset($endpoints[$typeKey])) {
            return Response::text(json_encode([
                'success' => false,
                'message' => "No endpoints of type '{$type}' found.",
            ]));
        }

        $found = false;
        foreach ($endpoints[$typeKey] as &$endpoint) {
            if (($endpoint['id'] ?? null) === $endpointId) {
                $endpoint['enabled'] = $enabled;
                $found = true;
                break;
            }
        }

        if (! $found) {
            return Response::text(json_encode([
                'success' => false,
                'message' => "Endpoint '{$endpointId}' not found.",
            ]));
        }

        app(UpdateBridgeEndpoints::class)->execute($connection, $endpoints);

        return Response::text(json_encode([
            'success' => true,
            'endpoint_id' => $endpointId,
            'enabled' => $enabled,
            'message' => "Endpoint '{$endpointId}' ".($enabled ? 'enabled' : 'disabled').'.',
        ]));
    }
}
