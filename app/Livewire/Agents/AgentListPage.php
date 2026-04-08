<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Actions\ImportAgentWorkspaceAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class AgentListPage extends Component
{
    use WithFileUploads, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    // Import
    public $importFile = null;

    public string $importMode = 'create';

    public ?string $mergeAgentId = null;

    public bool $showImportModal = false;

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function importWorkspace(): void
    {
        $this->validate(['importFile' => 'required|file|mimes:zip,yaml,yml|max:10240']);

        $action = app(ImportAgentWorkspaceAction::class);
        $result = $action->execute(
            $this->importFile,
            auth()->user()->current_team_id,
            $this->importMode,
            $this->mergeAgentId,
        );

        $this->showImportModal = false;
        $this->importFile = null;
        $this->importMode = 'create';
        $this->mergeAgentId = null;

        session()->flash('message', "Agent imported: {$result['agent_name']}");
        $this->redirect(route('agents.show', $result['agent_id']));
    }

    public function render()
    {
        $query = Agent::query()->notChatbotAgent()
            ->withCount('skills')
            ->withCount(['evolutionProposals as pending_evolution_proposals_count' => fn ($q) => $q->where('status', EvolutionProposalStatus::Pending)]);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('role', 'ilike', "%{$this->search}%")
                    ->orWhere('goal', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $allowedSorts = ['name', 'created_at', 'status', 'updated_at', 'provider', 'model'];
        $sortField = in_array($this->sortField, $allowedSorts) ? $this->sortField : 'created_at';
        $query->orderBy($sortField, $this->sortDirection);

        return view('livewire.agents.agent-list-page', [
            'agents' => $query->paginate(20),
            'statuses' => AgentStatus::cases(),
            'canCreate' => true,
        ])->layout('layouts.app', ['header' => 'Agents']);
    }
}
