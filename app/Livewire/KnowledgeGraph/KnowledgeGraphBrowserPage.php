<?php

namespace App\Livewire\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Actions\AddKnowledgeFactAction;
use App\Domain\KnowledgeGraph\Actions\InvalidateKgFactAction;
use App\Domain\KnowledgeGraph\Actions\UpdateKnowledgeFactAction;
use App\Domain\KnowledgeGraph\Enums\EntityType;
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

    // View/Edit modal state
    public ?array $viewingFact = null;

    public ?string $editingFactId = null;

    public string $editSourceName = '';

    public string $editSourceType = 'topic';

    public string $editRelationType = '';

    public string $editTargetName = '';

    public string $editTargetType = 'topic';

    public string $editFact = '';

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
            'sourceType' => ['required', EntityType::validationRule()],
            'relationType' => ['required', 'string', 'max:80'],
            'targetName' => ['required', 'string', 'max:255'],
            'targetType' => ['required', EntityType::validationRule()],
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

    public function viewFact(string $id): void
    {
        $edge = KgEdge::with(['sourceEntity', 'targetEntity'])->findOrFail($id);
        abort_unless($edge->team_id === auth()->user()->current_team_id, 403);

        $this->viewingFact = [
            'id' => $edge->id,
            'source_name' => $edge->sourceEntity?->name ?? '—',
            'source_type' => $edge->sourceEntity?->type ?? '',
            'relation_type' => $edge->relation_type,
            'edge_type' => $edge->edge_type ?? '—',
            'target_name' => $edge->targetEntity?->name ?? '—',
            'target_type' => $edge->targetEntity?->type ?? '',
            'fact' => $edge->fact,
            'valid_at' => $edge->valid_at?->toDateTimeString(),
            'invalid_at' => $edge->invalid_at?->toDateTimeString(),
            'expired_at' => $edge->expired_at?->toDateTimeString(),
            'attributes' => $edge->attributes ?? [],
            'created_at' => $edge->created_at?->toDateTimeString(),
            'updated_at' => $edge->updated_at?->toDateTimeString(),
        ];
        $this->editingFactId = null;
    }

    public function closeView(): void
    {
        $this->viewingFact = null;
    }

    public function editFact(string $id): void
    {
        $edge = KgEdge::with(['sourceEntity', 'targetEntity'])->findOrFail($id);
        abort_unless($edge->team_id === auth()->user()->current_team_id, 403);

        $this->editingFactId = $edge->id;
        $this->editSourceName = $edge->sourceEntity?->name ?? '';
        $this->editSourceType = $edge->sourceEntity?->type ?? 'topic';
        $this->editRelationType = $edge->relation_type ?? '';
        $this->editTargetName = $edge->targetEntity?->name ?? '';
        $this->editTargetType = $edge->targetEntity?->type ?? 'topic';
        $this->editFact = $edge->fact ?? '';
        $this->viewingFact = null;
    }

    public function updateFact(): void
    {
        $this->validate([
            'editSourceName' => ['required', 'string', 'max:255'],
            'editSourceType' => ['required', EntityType::validationRule()],
            'editRelationType' => ['required', 'string', 'max:80'],
            'editTargetName' => ['required', 'string', 'max:255'],
            'editTargetType' => ['required', EntityType::validationRule()],
            'editFact' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $edge = KgEdge::findOrFail($this->editingFactId);
            abort_unless($edge->team_id === auth()->user()->current_team_id, 403);

            app(UpdateKnowledgeFactAction::class)->execute(
                edge: $edge,
                sourceName: $this->editSourceName,
                sourceType: $this->editSourceType,
                relationType: $this->editRelationType,
                targetName: $this->editTargetName,
                targetType: $this->editTargetType,
                fact: $this->editFact,
            );

            $this->cancelEdit();
            $this->success = 'Fact updated.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function cancelEdit(): void
    {
        $this->editingFactId = null;
        $this->reset(['editSourceName', 'editSourceType', 'editRelationType', 'editTargetName', 'editTargetType', 'editFact']);
        $this->editSourceType = 'topic';
        $this->editTargetType = 'topic';
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
            'entityTypes' => EntityType::cases(),
        ])->layout('layouts.app', ['header' => 'Knowledge Graph']);
    }
}
