<?php

namespace App\Mcp\Tools\ProductGraph;

use App\Domain\ProductGraph\Enums\NodeStatus;
use App\Domain\ProductGraph\Enums\NodeType;
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
class ProductGraphQueryTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'product_graph_query';

    protected string $description = 'Search the product graph: list typed nodes (features, shared components, agent skills, standards, releases, etc.) filtered by type, status, tag or name. Returns each node with its inbound/outbound edge counts. Answers "where does this fit / what exists".';

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_type' => $schema->string()
                ->description('Filter by node type: product | feature | sub_feature | shared_component | agent_skill | standard | persona | tech_component | release'),
            'status' => $schema->string()
                ->description('Filter by status: planned | in_progress | implemented | deprecated'),
            'tag' => $schema->string()
                ->description('Filter to nodes carrying this tag'),
            'search' => $schema->string()
                ->description('Case-insensitive substring match on node name'),
            'limit' => $schema->integer()
                ->description('Max nodes to return (default 50, max 200)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'node_type' => 'nullable|string|in:'.implode(',', NodeType::values()),
            'status' => 'nullable|string|in:'.implode(',', NodeStatus::values()),
            'tag' => 'nullable|string|max:80',
            'search' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $query = ProductNode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->withCount(['outgoingEdges', 'incomingEdges']);

        if (! empty($validated['node_type'])) {
            $query->where('node_type', $validated['node_type']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $query->where('name', 'ilike', '%'.$validated['search'].'%');
        }

        if (! empty($validated['tag'])) {
            $query->whereJsonContains('tags', $validated['tag']);
        }

        $nodes = $query->orderBy('node_type')->orderBy('name')
            ->limit($validated['limit'] ?? 50)
            ->get();

        return Response::text(json_encode([
            'count' => $nodes->count(),
            'nodes' => $nodes->map(fn (ProductNode $n) => [
                'id' => $n->id,
                'name' => $n->name,
                'node_type' => $n->node_type->value,
                'status' => $n->status->value,
                'description' => $n->description,
                'tags' => $n->tags,
                'external_ref' => $n->external_ref,
                'depends_on_count' => $n->outgoing_edges_count,
                'depended_by_count' => $n->incoming_edges_count,
            ])->values()->toArray(),
        ]));
    }
}
