<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Actions\UpdateBridgeEndpoints;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
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
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null);
        $endpointId = $request->get('endpoint_id');
        $type = $request->get('type');
        $enabled = $request->boolean('enabled', true);

        if (! $endpointId || ! $type) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'endpoint_id and type are required.',
            ]));
        }

        // bridge_endpoint_list aggregates endpoints across every active bridge,
        // so search all of them (same order) rather than only the newest one.
        $connections = $teamId
            ? BridgeConnection::where('team_id', $teamId)
                ->active()
                ->orderByDesc('priority')
                ->orderByDesc('connected_at')
                ->get()
            : collect();

        if ($connections->isEmpty()) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'No active FleetQ Bridge connection found.',
            ]));
        }

        $typeKey = match ($type) {
            'llm' => 'llm_endpoints',
            'agent' => 'agents',
            'mcp_server' => 'mcp_servers',
            default => null,
        };

        if (! $typeKey) {
            return Response::text(json_encode([
                'success' => false,
                'message' => "No endpoints of type '{$type}' found.",
            ]));
        }

        $connection = null;
        $endpoints = [];
        foreach ($connections as $candidate) {
            $candidateEndpoints = $candidate->endpoints ?? [];
            if (! isset($candidateEndpoints[$typeKey]) || ! is_array($candidateEndpoints[$typeKey])) {
                continue;
            }

            foreach ($candidateEndpoints[$typeKey] as $index => $endpoint) {
                if (is_array($endpoint) && ($endpoint['id'] ?? null) === $endpointId) {
                    $candidateEndpoints[$typeKey][$index]['enabled'] = $enabled;
                    $connection = $candidate;
                    $endpoints = $candidateEndpoints;
                    break 2;
                }
            }
        }

        if (! $connection) {
            return Response::text(json_encode([
                'success' => false,
                'message' => "Endpoint '{$endpointId}' not found.",
            ]));
        }

        app(UpdateBridgeEndpoints::class)->execute($connection, $endpoints);

        return Response::text(json_encode([
            'success' => true,
            'endpoint_id' => $endpointId,
            'bridge_id' => $connection->id,
            'enabled' => $enabled,
            'message' => "Endpoint '{$endpointId}' ".($enabled ? 'enabled' : 'disabled').'.',
        ]));
    }
}
