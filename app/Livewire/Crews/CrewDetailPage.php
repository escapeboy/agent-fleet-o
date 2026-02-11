<?php

namespace App\Livewire\Crews;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\UpdateCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use Livewire\Component;

class CrewDetailPage extends Component
{
    public Crew $crew;

    public string $activeTab = 'overview';

    // Editing state
    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    public string $editProcessType = '';

    public string $editCoordinatorId = '';

    public string $editQaId = '';

    public array $editWorkerIds = [];

    public int $editMaxIterations = 3;

    public float $editQualityThreshold = 0.70;

    public function mount(Crew $crew): void
    {
        $this->crew = $crew;
    }

    public function toggleStatus(): void
    {
        $newStatus = $this->crew->status === CrewStatus::Active
            ? CrewStatus::Archived
            : CrewStatus::Active;

        $this->crew->update(['status' => $newStatus]);
        $this->crew->refresh();
    }

    public function activate(): void
    {
        $this->crew->update(['status' => CrewStatus::Active]);
        $this->crew->refresh();
    }

    public function startEdit(): void
    {
        $this->editName = $this->crew->name;
        $this->editDescription = $this->crew->description ?? '';
        $this->editProcessType = $this->crew->process_type->value;
        $this->editCoordinatorId = $this->crew->coordinator_agent_id;
        $this->editQaId = $this->crew->qa_agent_id;
        $this->editWorkerIds = $this->crew->workerMembers()->pluck('agent_id')->toArray();
        $this->editMaxIterations = $this->crew->max_task_iterations;
        $this->editQualityThreshold = $this->crew->quality_threshold;
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function toggleWorker(string $agentId): void
    {
        if (in_array($agentId, $this->editWorkerIds)) {
            $this->editWorkerIds = array_values(array_diff($this->editWorkerIds, [$agentId]));
        } else {
            $this->editWorkerIds[] = $agentId;
        }
    }

    public function save(UpdateCrewAction $action): void
    {
        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editCoordinatorId' => 'required|exists:agents,id',
            'editQaId' => 'required|exists:agents,id|different:editCoordinatorId',
            'editProcessType' => 'required|in:sequential,parallel,hierarchical',
            'editMaxIterations' => 'required|integer|min:1|max:10',
            'editQualityThreshold' => 'required|numeric|min:0|max:1',
        ]);

        try {
            $action->execute(
                crew: $this->crew,
                name: $this->editName,
                description: $this->editDescription ?: null,
                coordinatorAgentId: $this->editCoordinatorId,
                qaAgentId: $this->editQaId,
                processType: CrewProcessType::from($this->editProcessType),
                maxTaskIterations: $this->editMaxIterations,
                qualityThreshold: $this->editQualityThreshold,
                workerAgentIds: $this->editWorkerIds,
            );

            $this->crew->refresh();
            $this->editing = false;
            session()->flash('message', 'Crew updated successfully.');
        } catch (\InvalidArgumentException $e) {
            $this->addError('editCoordinatorId', $e->getMessage());
        }
    }

    public function deleteCrew(): void
    {
        $this->crew->delete();
        session()->flash('message', 'Crew deleted.');
        $this->redirect(route('crews.index'));
    }

    public function render()
    {
        $agents = Agent::where('status', AgentStatus::Active)->orderBy('name')->get();
        $members = $this->crew->members()->with('agent')->orderBy('sort_order')->get();
        $executions = $this->crew->executions()->with('taskExecutions')->limit(20)->get();

        return view('livewire.crews.crew-detail-page', [
            'agents' => $agents,
            'members' => $members,
            'executions' => $executions,
            'processTypes' => CrewProcessType::cases(),
        ])->layout('layouts.app', ['header' => $this->crew->name]);
    }
}
