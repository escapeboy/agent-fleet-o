<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Models\BridgeConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class BridgeRenameTool extends Tool
{
    protected string $name = 'bridge_rename';

    protected string $description = 'Rename a bridge connection. Sets a human-friendly label (e.g. "Dev Laptop", "GPU Server").';

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->string()
                ->description('UUID of the bridge connection to rename.')
                ->required(),
            'label' => $schema->string()
                ->description('New label for the bridge (max 100 chars).')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $connectionId = $request->input('connection_id');
        $label = $request->input('label');

        if (! $connectionId || ! $label) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'Both connection_id and label are required.',
            ]));
        }

        $connection = BridgeConnection::where('team_id', $teamId)
            ->where('id', $connectionId)
            ->first();

        if (! $connection) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'Bridge connection not found.',
            ]));
        }

        $connection->update(['label' => mb_substr($label, 0, 100)]);

        return Response::text(json_encode([
            'success' => true,
            'id' => $connection->id,
            'label' => $connection->label,
            'message' => "Bridge renamed to \"{$connection->label}\".",
        ]));
    }
}
