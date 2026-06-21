<?php

namespace App\Livewire\ProductGraph;

use App\Domain\ProductGraph\Actions\CreateNodeAction;
use App\Domain\ProductGraph\Actions\DeleteNodeAction;
use App\Domain\ProductGraph\Actions\ImportFromInventoryAction;
use App\Domain\ProductGraph\Actions\UpsertEdgeAction;
use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Enums\NodeStatus;
use App\Domain\ProductGraph\Enums\NodeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use App\Domain\ProductGraph\Models\ProductNode;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductGraphBrowserPage extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $tab = 'map';

    #[Url]
    public string $nodeTypeFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $search = '';

    public ?string $selectedNodeId = null;

    public bool $showAddNode = false;

    public string $newName = '';

    public string $newNodeType = 'feature';

    public string $newStatus = 'planned';

    public string $newDescription = '';

    public string $newTags = '';

    public bool $showAddEdge = false;

    public string $edgeSource = '';

    public string $edgeTarget = '';

    public string $edgeType = 'depends_on';

    public ?string $error = null;

    public ?string $success = null;

    private function teamId(): string
    {
        return auth()->user()->current_team_id;
    }

    public function addNode(): void
    {
        $this->authorize('edit-content');

        $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newNodeType' => ['required', NodeType::validationRule()],
            'newStatus' => ['required', NodeStatus::validationRule()],
            'newDescription' => ['nullable', 'string', 'max:2000'],
        ]);

        $tags = array_values(array_filter(array_map('trim', explode(',', $this->newTags))));

        app(CreateNodeAction::class)->execute(
            teamId: $this->teamId(),
            type: NodeType::from($this->newNodeType),
            name: $this->newName,
            status: NodeStatus::from($this->newStatus),
            description: $this->newDescription ?: null,
            tags: $tags,
        );

        $this->reset(['newName', 'newDescription', 'newTags', 'error']);
        $this->showAddNode = false;
        $this->success = 'Node added.';
    }

    public function addEdge(): void
    {
        $this->authorize('edit-content');

        $this->validate([
            'edgeSource' => ['required', 'string'],
            'edgeTarget' => ['required', 'string'],
            'edgeType' => ['required', EdgeType::validationRule()],
        ]);

        try {
            app(UpsertEdgeAction::class)->execute(
                teamId: $this->teamId(),
                sourceNodeId: $this->edgeSource,
                targetNodeId: $this->edgeTarget,
                type: EdgeType::from($this->edgeType),
            );
            $this->reset(['edgeSource', 'edgeTarget', 'error']);
            $this->showAddEdge = false;
            $this->success = 'Edge added.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function deleteNode(string $id): void
    {
        $this->authorize('edit-content');

        $node = ProductNode::withoutGlobalScopes()
            ->where('team_id', $this->teamId())
            ->find($id);

        if ($node) {
            app(DeleteNodeAction::class)->execute($node);
            $this->success = 'Node deleted.';
            if ($this->selectedNodeId === $id) {
                $this->selectedNodeId = null;
            }
        }
    }

    public function selectNode(string $id): void
    {
        $this->selectedNodeId = $id;
    }

    public function closeDrawer(): void
    {
        $this->selectedNodeId = null;
    }

    public function importInventory(): void
    {
        $this->authorize('edit-content');

        $markdown = $this->resolveInventoryMarkdown();

        if ($markdown === null) {
            $this->error = 'No feature-inventory document found to import from.';

            return;
        }

        $result = app(ImportFromInventoryAction::class)->execute($this->teamId(), $markdown);
        $this->success = "Imported: {$result['nodes_created']} nodes, {$result['edges_created']} edges.";
    }

    private function resolveInventoryMarkdown(): ?string
    {
        // Cloud: parent repo docs/ sits one level above base_path(); community: base/docs.
        $candidates = glob(dirname(base_path()).'/docs/feature-inventory-full_*.md') ?: [];
        $candidates[] = base_path('docs/capabilities.md');

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }

    public function render()
    {
        if (! config('productgraph.enabled')) {
            return view('livewire.product-graph.product-graph-browser-page', ['disabled' => true]);
        }

        $teamId = $this->teamId();

        $nodesQuery = ProductNode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->withCount(['outgoingEdges', 'incomingEdges']);

        if ($this->nodeTypeFilter !== '') {
            $nodesQuery->where('node_type', $this->nodeTypeFilter);
        }
        if ($this->statusFilter !== '') {
            $nodesQuery->where('status', $this->statusFilter);
        }
        if ($this->search !== '') {
            $nodesQuery->where('name', 'ilike', '%'.$this->search.'%');
        }

        $nodes = $nodesQuery->orderBy('node_type')->orderBy('name')->get();

        $selected = null;
        if ($this->selectedNodeId !== null) {
            $selected = ProductNode::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->find($this->selectedNodeId);
        }

        return view('livewire.product-graph.product-graph-browser-page', [
            'disabled' => false,
            'nodes' => $nodes,
            'graph' => $this->buildLayout($nodes),
            'releases' => ProductNode::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('node_type', NodeType::Release->value)
                ->orderByDesc('created_at')
                ->get(),
            'selected' => $selected,
            'selectedEdges' => $selected ? $this->edgesFor($teamId, $selected) : ['out' => collect(), 'in' => collect()],
            'pendingCount' => ProductGraphChange::withoutGlobalScopes()
                ->where('team_id', $teamId)->pending()->count(),
            'allNodes' => $nodes,
            'nodeTypes' => NodeType::cases(),
            'statuses' => NodeStatus::cases(),
            'edgeTypes' => EdgeType::cases(),
        ]);
    }

    /**
     * Server-computed columnar layout (one column per node type). Capped for SVG sanity.
     *
     * @param  Collection<int, ProductNode>  $nodes
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function buildLayout($nodes): array
    {
        $capped = $nodes->take(80);
        $columns = array_values(NodeType::values());
        $colCounts = [];
        $positions = [];
        $svgNodes = [];

        foreach ($capped as $node) {
            $col = array_search($node->node_type->value, $columns, true) ?: 0;
            $row = $colCounts[$col] ?? 0;
            $colCounts[$col] = $row + 1;

            $x = $col * 200 + 70;
            $y = $row * 64 + 50;
            $positions[$node->id] = [$x, $y];

            $svgNodes[] = [
                'id' => $node->id,
                'name' => $node->name,
                'type' => $node->node_type->value,
                'status' => $node->status->value,
                'x' => $x,
                'y' => $y,
            ];
        }

        $edges = ProductEdge::withoutGlobalScopes()
            ->where('team_id', $this->teamId())
            ->whereIn('source_node_id', array_keys($positions))
            ->whereIn('target_node_id', array_keys($positions))
            ->get();

        $svgEdges = [];
        foreach ($edges as $edge) {
            [$x1, $y1] = $positions[$edge->source_node_id];
            [$x2, $y2] = $positions[$edge->target_node_id];
            $svgEdges[] = [
                'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2,
                'type' => $edge->edge_type->value,
            ];
        }

        return ['nodes' => $svgNodes, 'edges' => $svgEdges];
    }

    /**
     * @return array{out: Collection<int, ProductEdge>, in: Collection<int, ProductEdge>}
     */
    private function edgesFor(string $teamId, ProductNode $node): array
    {
        return [
            'out' => ProductEdge::withoutGlobalScopes()->where('team_id', $teamId)
                ->where('source_node_id', $node->id)->with('target')->get(),
            'in' => ProductEdge::withoutGlobalScopes()->where('team_id', $teamId)
                ->where('target_node_id', $node->id)->with('source')->get(),
        ];
    }
}
