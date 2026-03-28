<?php

namespace App\Livewire\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Actions\AddKnowledgeFactAction;
use App\Domain\KnowledgeGraph\Actions\InvalidateKgFactAction;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class KnowledgeGraphBrowserPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $edgeTypeFilter = '';

    #[Url]
    public string $relationTypeFilter = '';

    #[Url]
    public string $entityTypeFilter = '';

    #[Url]
    public bool $includeHistory = false;

    public bool $showAddForm = false;

    public string $sourceName = '';

    public string $sourceType = 'topic';

    public string $relationType = '';

    public string $targetName = '';

    public string $targetType = 'topic';

    public string $fact = '';

    public ?string $error = null;

    public ?string $success = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEdgeTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRelationTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEntityTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedIncludeHistory(): void
    {
        $this->resetPage();
    }

    public function invalidateFact(string $id): void
    {
        $edge = KgEdge::findOrFail($id);
        abort_unless($edge->team_id === auth()->user()->current_team_id, 403);
        app(InvalidateKgFactAction::class)->execute($edge);
        $this->success = 'Fact invalidated.';
        $this->error = null;
    }

    public function deleteFact(string $id): void
    {
        $edge = KgEdge::findOrFail($id);
        abort_unless($edge->team_id === auth()->user()->current_team_id, 403);
        $edge->delete();
        $this->success = 'Fact deleted.';
        $this->error = null;
    }

    public function addFact(): void
    {
        $this->validate([
            'sourceName' => ['required', 'string', 'max:255'],
            'sourceType' => ['required', 'in:person,company,location,date,product,topic'],
            'relationType' => ['required', 'string', 'max:80'],
            'targetName' => ['required', 'string', 'max:255'],
            'targetType' => ['required', 'in:person,company,location,date,product,topic'],
            'fact' => ['required', 'string', 'max:2000'],
        ]);

        try {
            app(AddKnowledgeFactAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                sourceName: $this->sourceName,
                sourceType: $this->sourceType,
                relationType: $this->relationType,
                targetName: $this->targetName,
                targetType: $this->targetType,
                fact: $this->fact,
            );

            $this->reset(['sourceName', 'sourceType', 'relationType', 'targetName', 'targetType', 'fact', 'error']);
            $this->sourceType = 'topic';
            $this->targetType = 'topic';
            $this->showAddForm = false;
            $this->success = 'Fact added to knowledge graph.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $teamId = auth()->user()->current_team_id;

        $query = KgEdge::query()
            ->with(['sourceEntity', 'targetEntity'])
            ->where('team_id', $teamId)
            ->where('source_node_type', KgEdge::NODE_TYPE_ENTITY)
            ->where('target_node_type', KgEdge::NODE_TYPE_ENTITY);

        if (! $this->includeHistory) {
            $query->whereNull('invalid_at');
        }

        if ($this->search) {
            $query->where('fact', 'ilike', "%{$this->search}%");
        }

        if ($this->edgeTypeFilter) {
            $query->where('edge_type', $this->edgeTypeFilter);
        }

        if ($this->relationTypeFilter) {
            $query->where('relation_type', 'ilike', "%{$this->relationTypeFilter}%");
        }

        if ($this->entityTypeFilter) {
            $query->whereHas('sourceEntity', fn ($q) => $q->where('type', $this->entityTypeFilter));
        }

        $facts = $query->latest('valid_at')->paginate(25);

        $stats = [
            'total' => KgEdge::where('team_id', $teamId)->whereNull('invalid_at')->count(),
            'invalidated' => KgEdge::where('team_id', $teamId)->whereNotNull('invalid_at')->count(),
            'relation_types' => KgEdge::where('team_id', $teamId)->whereNull('invalid_at')->distinct()->count('relation_type'),
        ];

        $relationTypes = KgEdge::where('team_id', $teamId)
            ->whereNull('invalid_at')
            ->distinct()
            ->pluck('relation_type')
            ->sort()
            ->values();

        return view('livewire.knowledge-graph.knowledge-graph-browser-page', [
            'facts' => $facts,
            'stats' => $stats,
            'relationTypes' => $relationTypes,
        ])->layout('layouts.app', ['header' => 'Knowledge Graph']);
    }
}
