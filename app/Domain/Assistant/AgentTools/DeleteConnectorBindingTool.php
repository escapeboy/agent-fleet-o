<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Signal\Models\ConnectorBinding;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DeleteConnectorBindingTool implements Tool
{
    public function name(): string
    {
        return 'delete_connector_binding';
    }

    public function description(): string
    {
        return 'Delete a connector binding (DM pairing / sender approval). This will prevent the sender from communicating via this channel. Destructive.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'binding_id' => $schema->string()->required()->description('The connector binding UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $teamId = auth()->user()?->current_team_id;

        if (! $teamId) {
            return json_encode(['error' => 'No current team.']);
        }

        $binding = ConnectorBinding::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('binding_id'));

        if (! $binding) {
            return json_encode(['error' => 'Connector binding not found.']);
        }

        try {
            $channel = $binding->channel;
            $externalName = $binding->external_name ?? $binding->external_id;
            $binding->delete();

            return json_encode([
                'success' => true,
                'binding_id' => $request->get('binding_id'),
                'message' => "Binding for '{$externalName}' on {$channel} deleted.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
