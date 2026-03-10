<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Actions\TerminateBridgeConnection;
use App\Domain\Bridge\Models\BridgeConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class BridgeDisconnectTool extends Tool
{
    protected string $name = 'bridge_disconnect';

    protected string $description = 'Terminate the active FleetQ Bridge session for the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        $connection = $teamId
            ? BridgeConnection::where('team_id', $teamId)
                ->active()
                ->orderByDesc('connected_at')
                ->first()
            : null;

        if (! $connection) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'No active FleetQ Bridge connection to disconnect.',
            ]));
        }

        app(TerminateBridgeConnection::class)->execute($connection);

        return Response::text(json_encode([
            'success' => true,
            'message' => 'FleetQ Bridge connection terminated.',
            'session_id' => $connection->session_id,
        ]));
    }
}
