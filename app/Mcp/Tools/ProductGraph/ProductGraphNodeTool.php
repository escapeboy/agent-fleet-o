<?php

namespace App\Mcp\Tools\ProductGraph;

use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductNode;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class ProductGraphNodeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'product_graph_node';

    protected string $description = 'Get one product-graph node with its full upstream and downstream edges (what it connects to). Answers "what does this connect to".';

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->string()
                ->description('UUID of the node to fetch')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'node_id' => 'required|string',
        ]);

        $node = ProductNode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['node_id']);

        if (! $node) {
            return $this->notFoundError('product node', $validated['node_id']);
        }

        $outgoing = ProductEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('source_node_id', $node->id)
            ->with('target')
            ->get();

        $incoming = ProductEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('target_node_id', $node->id)
            ->with('source')
            ->get();

        return Response::text(json_encode([
            'id' => $node->id,
            'name' => $node->name,
            'node_type' => $node->node_type->value,
            'status' => $node->status->value,
            'description' => $node->description,
            'tags' => $node->tags,
            'external_ref' => $node->external_ref,
            'outgoing_edges' => $outgoing->map(fn (ProductEdge $e) => [
                'edge_type' => $e->edge_type->value,
                'target_id' => $e->target_node_id,
                'target_name' => $e->target?->name,
                'description' => $e->description,
            ])->values()->toArray(),
            'incoming_edges' => $incoming->map(fn (ProductEdge $e) => [
                'edge_type' => $e->edge_type->value,
                'source_id' => $e->source_node_id,
                'source_name' => $e->source?->name,
                'description' => $e->description,
            ])->values()->toArray(),
        ]));
    }
}
