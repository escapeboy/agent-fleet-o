<?php

namespace App\Mcp\Tools\ProductGraph;

use App\Domain\ProductGraph\Models\ProductNode;
use App\Domain\ProductGraph\Services\ImpactAnalyzer;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class ProductGraphImpactTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'product_graph_impact';

    protected string $description = 'Blast-radius analysis: given a node, return every node potentially affected if it changes, with depth and the edge type each impact propagates through. Answers "what breaks if this changes".';

    public function __construct(private readonly ImpactAnalyzer $analyzer) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->string()
                ->description('UUID of the node to analyse')
                ->required(),
            'max_depth' => $schema->integer()
                ->description('Maximum propagation depth (default from config, typically 5)'),
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
            'max_depth' => 'nullable|integer|min:1|max:20',
        ]);

        $node = ProductNode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['node_id']);

        if (! $node) {
            return $this->notFoundError('product node', $validated['node_id']);
        }

        $impact = $this->analyzer->blastRadius($node, $validated['max_depth'] ?? null);

        return Response::text(json_encode([
            'node_id' => $node->id,
            'node_name' => $node->name,
            'affected_count' => count($impact),
            'affected' => $impact,
        ]));
    }
}
