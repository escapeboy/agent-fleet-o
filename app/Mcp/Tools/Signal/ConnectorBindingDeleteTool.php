<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\ConnectorBinding;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

#[IsDestructive]
#[AssistantTool('read')]
class ConnectorBindingDeleteTool extends Tool
{
    protected string $name = 'connector_binding_delete';

    protected string $description = 'Delete a connector binding (DM pairing / sender approval) by UUID. This will prevent the sender from communicating with the platform via this channel.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'binding_id' => $schema->string()
                ->description('The connector binding UUID to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = app('mcp.team_id') ?? $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $bindingId = $request->get('binding_id');

        $binding = ConnectorBinding::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($bindingId);

        if (! $binding) {
            return Response::error("Connector binding {$bindingId} not found. Use connector_binding_manage with action=list to discover binding IDs.");
        }

        try {
            $channel = $binding->channel;
            $externalName = $binding->external_name ?? $binding->external_id;
            $binding->delete();

            return Response::text(json_encode([
                'success' => true,
                'binding_id' => $bindingId,
                'channel' => $channel,
                'external_name' => $externalName,
                'deleted_at' => now()->toIso8601String(),
                'message' => "Binding for '{$externalName}' on {$channel} has been deleted.",
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
