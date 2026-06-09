<?php

declare(strict_types=1);

namespace App\Livewire\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Actions\BuildKgCommunitiesAction;
use App\Domain\KnowledgeGraph\Models\KgCommunity;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class KgCommunitiesPage extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $search = '';

    public ?string $expandedId = null;

    public ?string $error = null;

    public ?string $success = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleExpanded(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function rebuild(): void
    {
        $this->authorize('edit-content');

        $teamId = auth()->user()->current_team_id;

        try {
            app(BuildKgCommunitiesAction::class)->execute($teamId);
            $this->success = 'Communities rebuilt.';
            $this->error = null;
            $this->resetPage();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->success = null;
        }
    }

    public function render()
    {
        $teamId = auth()->user()->current_team_id;

        $query = KgCommunity::query()
            ->where('team_id', $teamId);

        if ($this->search !== '') {
            $query->whereRaw('lower(label) like ?', ['%'.mb_strtolower($this->search).'%']);
        }

        $communities = $query
            ->orderByDesc('size')
            ->paginate(20);

        $stats = [
            'total' => KgCommunity::where('team_id', $teamId)->count(),
            'largest' => (int) KgCommunity::where('team_id', $teamId)->max('size'),
            'entities' => (int) KgCommunity::where('team_id', $teamId)->sum('size'),
        ];

        return view('livewire.knowledge-graph.kg-communities-page', [
            'communities' => $communities,
            'stats' => $stats,
        ])->layout('layouts.app', ['header' => 'KG Communities']);
    }
}
