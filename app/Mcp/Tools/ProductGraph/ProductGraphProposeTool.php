<?php

namespace App\Mcp\Tools\ProductGraph;

use App\Domain\ProductGraph\Actions\ProposeChangeAction;
use App\Domain\ProductGraph\Enums\ChangeType;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Agents propose graph changes through this tool — the change enters a human
 * review queue and does NOT mutate the graph until a human approves it.
 */
#[IsDestructive]
#[AssistantTool('write')]
class ProductGraphProposeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'product_graph_propose';

    protected string $description = 'Propose a change to the product graph (create/update/delete a node or edge). The proposal lands in a human review queue and is NOT applied until approved. Use this to keep the graph current as you build.';

    public function __construct(private readonly ProposeChangeAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'change_type' => $schema->string()
                ->description('One of: create_node | update_node | delete_node | create_edge | delete_edge')
                ->required(),
            'target_id' => $schema->string()
                ->description('UUID of the existing node/edge (required for update_node, delete_node, delete_edge)'),
            'payload' => $schema->object()
                ->description('Proposed state. create_node: {node_type,name,status?,description?,tags?,external_ref?}. create_edge: {source_node_id,target_node_id,edge_type,description?}. update_node: changed fields.'),
            'proposed_by_label' => $schema->string()
                ->description('Who is proposing, e.g. "agent:claude-code" (default "agent")'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'change_type' => 'required|string|in:'.implode(',', ChangeType::values()),
            'target_id' => 'nullable|string',
            'payload' => 'nullable|array',
            'proposed_by_label' => 'nullable|string|max:120',
        ]);

        try {
            $change = $this->action->execute(
                teamId: $teamId,
                type: ChangeType::from($validated['change_type']),
                targetId: $validated['target_id'] ?? null,
                payload: $validated['payload'] ?? [],
                proposedByLabel: $validated['proposed_by_label'] ?? 'agent',
            );
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $change->id,
            'status' => $change->status->value,
            'change_type' => $change->change_type->value,
            'message' => 'Proposal queued for human review. The graph is unchanged until approved.',
        ]));
    }
}
