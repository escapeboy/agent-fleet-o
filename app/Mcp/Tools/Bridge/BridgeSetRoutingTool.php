<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class BridgeSetRoutingTool extends Tool
{
    protected string $name = 'bridge_set_routing';

    protected string $description = 'Configure bridge agent routing preferences. Modes: "auto" (any online bridge), "prefer" (prefer a specific bridge with fallback), "per_agent" (pin agents to specific bridges).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'routing_mode' => $schema->string()
                ->description('Routing strategy: auto, prefer, or per_agent.')
                ->enum(['auto', 'prefer', 'per_agent'])
                ->required(),
            'preferred_bridge_id' => $schema->string()
                ->description('UUID of the preferred bridge connection. Required when routing_mode is "prefer".'),
            'agent_routing' => $schema->object()
                ->description('Map of agent_key → bridge_connection_id. Used when routing_mode is "per_agent". Agents not listed fall back to auto.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $mode = $request->input('routing_mode');
        $preferredId = $request->input('preferred_bridge_id');
        $agentRouting = $request->input('agent_routing', []);

        if (! in_array($mode, ['auto', 'prefer', 'per_agent'])) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'Invalid routing_mode. Must be auto, prefer, or per_agent.',
            ]));
        }

        $team = Team::find($teamId);

        if (! $team) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'Team not found.',
            ]));
        }

        $settings = $team->settings ?? [];

        $bridgeSettings = ['routing_mode' => $mode];

        if ($mode === 'prefer' && $preferredId) {
            $bridgeSettings['preferred_bridge_id'] = $preferredId;
        }

        if ($mode === 'per_agent' && ! empty($agentRouting)) {
            $bridgeSettings['agent_routing'] = array_filter(
                (array) $agentRouting,
                fn ($v) => $v && $v !== 'auto',
            );
        }

        $settings['bridge'] = $bridgeSettings;
        $team->update(['settings' => $settings]);

        return Response::text(json_encode([
            'success' => true,
            'routing' => $bridgeSettings,
            'message' => "Bridge routing set to \"{$mode}\".",
        ]));
    }
}
