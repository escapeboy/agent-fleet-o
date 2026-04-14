<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Actions\TerminateBridgeConnection;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class BridgeDisconnectTool extends Tool
{
    protected string $name = 'bridge_disconnect';

    protected string $description = 'Disconnect a FleetQ Bridge. Pass connection_id to disconnect a specific bridge, or omit to disconnect all active bridges.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->string()
                ->description('UUID of a specific bridge connection to disconnect. Omit to disconnect all.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $connectionId = $request->input('connection_id');

        $query = BridgeConnection::where('team_id', $teamId)->active();

        if ($connectionId) {
            $query->where('id', $connectionId);
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'No active FleetQ Bridge connection to disconnect.',
            ]));
        }

        $connections->each(fn ($c) => app(TerminateBridgeConnection::class)->execute($c));

        return Response::text(json_encode([
            'success' => true,
            'disconnected' => $connections->count(),
            'message' => $connections->count() === 1
                ? 'Bridge connection terminated.'
                : "{$connections->count()} bridge connections terminated.",
        ]));
    }
}
