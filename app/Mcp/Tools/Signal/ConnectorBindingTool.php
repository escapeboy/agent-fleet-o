<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Enums\ConnectorBindingStatus;
use App\Domain\Signal\Models\ConnectorBinding;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ConnectorBindingTool extends Tool
{
    protected string $name = 'connector_binding_manage';

    protected string $description = 'Manage inbound sender approvals (DM pairing). List pending bindings and approve or block senders.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | approve | block | get')
                ->enum(['list', 'approve', 'block', 'get'])
                ->required(),
            'binding_id' => $schema->string()
                ->description('Binding UUID (required for approve/block/get)'),
            'status_filter' => $schema->string()
                ->description('Filter by status: pending | approved | blocked')
                ->enum(['pending', 'approved', 'blocked']),
            'channel_filter' => $schema->string()
                ->description('Filter by channel: telegram | whatsapp | discord | signal_protocol | matrix'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $action = $request->get('action', 'list');

        if ($action === 'list') {
            $query = ConnectorBinding::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->orderBy('created_at', 'desc');

            if ($status = $request->get('status_filter')) {
                $query->where('status', $status);
            }

            if ($channel = $request->get('channel_filter')) {
                $query->where('channel', $channel);
            }

            $bindings = $query->limit(50)->get()->map(fn ($b) => [
                'id' => $b->id,
                'channel' => $b->channel,
                'external_id' => $b->external_id,
                'external_name' => $b->external_name,
                'status' => $b->status->value,
                'pairing_code' => $b->isPending() ? $b->pairing_code : null,
                'pairing_code_expires_at' => $b->pairing_code_expires_at?->toIso8601String(),
                'approved_at' => $b->approved_at?->toIso8601String(),
                'created_at' => $b->created_at->toIso8601String(),
            ]);

            return Response::text(json_encode(['bindings' => $bindings, 'total' => $bindings->count()]));
        }

        if ($action === 'get') {
            $bindingId = $request->get('binding_id');
            if (! $bindingId) {
                return Response::error('binding_id is required for get action.');
            }

            $binding = ConnectorBinding::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->findOrFail($bindingId);

            return Response::text(json_encode([
                'id' => $binding->id,
                'channel' => $binding->channel,
                'external_id' => $binding->external_id,
                'external_name' => $binding->external_name,
                'status' => $binding->status->value,
                'pairing_code' => $binding->isPending() ? $binding->pairing_code : null,
                'pairing_code_expires_at' => $binding->pairing_code_expires_at?->toIso8601String(),
                'approved_at' => $binding->approved_at?->toIso8601String(),
                'metadata' => $binding->metadata,
                'created_at' => $binding->created_at->toIso8601String(),
            ]));
        }

        if ($action === 'approve') {
            $bindingId = $request->get('binding_id');
            if (! $bindingId) {
                return Response::error('binding_id is required for approve action.');
            }

            $binding = ConnectorBinding::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->findOrFail($bindingId);

            $binding->update([
                'status' => ConnectorBindingStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $user?->id,
            ]);

            return Response::text("Sender {$binding->external_id} on {$binding->channel} approved.");
        }

        if ($action === 'block') {
            $bindingId = $request->get('binding_id');
            if (! $bindingId) {
                return Response::error('binding_id is required for block action.');
            }

            $binding = ConnectorBinding::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->findOrFail($bindingId);

            $binding->update(['status' => ConnectorBindingStatus::Blocked]);

            return Response::text("Sender {$binding->external_id} on {$binding->channel} blocked.");
        }

        return Response::error("Unknown action: {$action}");
    }
}
