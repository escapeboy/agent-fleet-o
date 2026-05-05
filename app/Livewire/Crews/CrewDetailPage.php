<?php

namespace App\Livewire\Crews;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\UpdateCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use Illuminate\Support\Facades\Gate;
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

    /**
     * Per-worker constraint overrides keyed by agent_id.
     *
     * @var array<string, array{tool_allowlist: string, max_steps: string, max_credits: string}>
     */
    public array $editWorkerConstraints = [];

    public int $editMaxIterations = 3;

    public float $editQualityThreshold = 0.70;

    // Inline member policy editing state
    public ?string $editingMemberId = null;

    public string $editMemberToolAllowlist = '';

    public string $editMemberMaxSteps = '';

    public string $editMemberMaxCredits = '';

    public function mount(Crew $crew): void
    {
        $this->crew = $crew;
    }

    public function toggleStatus(): void
    {
        Gate::authorize('edit-content');

        $newStatus = $this->crew->status === CrewStatus::Active
            ? CrewStatus::Archived
            : CrewStatus::Active;

        $this->crew->update(['status' => $newStatus]);
        $this->crew->refresh();
    }

    public function activate(): void
    {
        Gate::authorize('edit-content');

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
        $workerMembers = $this->crew->workerMembers()->get();
        $this->editWorkerIds = $workerMembers->pluck('agent_id')->toArray();
        // Populate existing constraint values for each current worker
        $this->editWorkerConstraints = [];
        foreach ($workerMembers as $member) {
            $this->editWorkerConstraints[$member->agent_id] = [
                'tool_allowlist' => implode(', ', $member->tool_allowlist ?? []),
                'max_steps' => $member->max_steps !== null ? (string) $member->max_steps : '',
                'max_credits' => $member->max_credits !== null ? (string) $member->max_credits : '',
            ];
        }
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
            unset($this->editWorkerConstraints[$agentId]);
        } else {
            $this->editWorkerIds[] = $agentId;
            $this->editWorkerConstraints[$agentId] ??= ['tool_allowlist' => '', 'max_steps' => '', 'max_credits' => ''];
        }
    }

    /**
     * Open inline constraint editor for a specific crew member.
     */
    public function startEditMemberPolicy(string $memberId): void
    {
        $member = CrewMember::find($memberId);
        if (! $member || $member->crew_id !== $this->crew->id) {
            return;
        }

        $this->editingMemberId = $memberId;
        $this->editMemberToolAllowlist = implode(', ', $member->tool_allowlist ?? []);
        $this->editMemberMaxSteps = $member->max_steps !== null ? (string) $member->max_steps : '';
        $this->editMemberMaxCredits = $member->max_credits !== null ? (string) $member->max_credits : '';
    }

    /**
     * Save inline constraint edits for a specific crew member.
     */
    public function saveMemberPolicy(UpdateCrewAction $action): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'editMemberMaxSteps' => 'nullable|integer|min:1|max:100',
            'editMemberMaxCredits' => 'nullable|integer|min:1|max:1000000',
        ]);

        $member = CrewMember::find($this->editingMemberId);
        if (! $member || $member->crew_id !== $this->crew->id) {
            $this->editingMemberId = null;

            return;
        }

        $action->updateMemberPolicy($member, [
            'tool_allowlist' => $this->editMemberToolAllowlist,
            'max_steps' => $this->editMemberMaxSteps,
            'max_credits' => $this->editMemberMaxCredits,
        ]);

        $this->editingMemberId = null;
        $this->resetValidation();
        session()->flash('message', "Member policy updated for {$member->agent->name}.");
    }

    /**
     * Cancel inline member policy editing.
     */
    public function cancelMemberPolicy(): void
    {
        $this->editingMemberId = null;
        $this->resetValidation();
    }

    public function save(UpdateCrewAction $action): void
    {
        Gate::authorize('edit-content');

        $teamId = auth()->user()->current_team_id;

        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editCoordinatorId' => "required|exists:agents,id,team_id,{$teamId}",
            'editQaId' => "required|exists:agents,id,team_id,{$teamId}|different:editCoordinatorId",
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
                workerConstraints: $this->editWorkerConstraints,
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
        Gate::authorize('edit-content');

        $this->crew->delete();
        session()->flash('message', 'Crew deleted.');
        $this->redirect(route('crews.index'));
    }

    public function render()
    {
        $agents = Agent::where('status', AgentStatus::Active)->orderBy('name')->get();
        $members = $this->crew->members()->with('agent')->orderBy('sort_order')->get();
        $executions = $this->crew->executions()->with(['taskExecutions', 'artifacts'])->latest()->limit(20)->get();

        return view('livewire.crews.crew-detail-page', [
            'agents' => $agents,
            'members' => $members,
            'executions' => $executions,
            'processTypes' => CrewProcessType::cases(),
        ])->layout('layouts.app', ['header' => $this->crew->name]);
    }
}
