<?php

namespace App\Livewire\Crews;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use Livewire\Component;

class CreateCrewForm extends Component
{
    public string $name = '';

    public string $description = '';

    public string $processType = 'hierarchical';

    public string $coordinatorAgentId = '';

    public string $qaAgentId = '';

    public array $workerAgentIds = [];

    public int $maxTaskIterations = 3;

    public float $qualityThreshold = 0.70;

    public string $convergenceMode = 'any_validated';

    public float $minValidatedRatio = 1.0;

    protected function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'coordinatorAgentId' => 'required|exists:agents,id',
            'qaAgentId' => 'required|exists:agents,id|different:coordinatorAgentId',
            'processType' => 'required|in:sequential,parallel,hierarchical,self_claim,adversarial',
            'maxTaskIterations' => 'required|integer|min:1|max:10',
            'qualityThreshold' => 'required|numeric|min:0|max:1',
            'convergenceMode' => 'required|in:any_validated,all_validated,threshold_ratio,quality_gate',
            'minValidatedRatio' => 'required_if:convergenceMode,threshold_ratio|numeric|min:0|max:1',
        ];
    }

    public function toggleWorker(string $agentId): void
    {
        if (in_array($agentId, $this->workerAgentIds)) {
            $this->workerAgentIds = array_values(array_diff($this->workerAgentIds, [$agentId]));
        } else {
            $this->workerAgentIds[] = $agentId;
        }
    }

    public function save(CreateCrewAction $action): void
    {
        $this->validate();

        try {
            $settings = ['convergence_mode' => $this->convergenceMode];
            if ($this->convergenceMode === 'threshold_ratio') {
                $settings['min_validated_ratio'] = $this->minValidatedRatio;
            }

            $crew = $action->execute(
                userId: auth()->id(),
                name: $this->name,
                coordinatorAgentId: $this->coordinatorAgentId,
                qaAgentId: $this->qaAgentId,
                description: $this->description ?: null,
                processType: CrewProcessType::from($this->processType),
                maxTaskIterations: $this->maxTaskIterations,
                qualityThreshold: $this->qualityThreshold,
                workerAgentIds: $this->workerAgentIds,
                settings: $settings,
                teamId: auth()->user()->currentTeam?->id,
            );

            session()->flash('message', "Crew '{$crew->name}' created successfully.");
            $this->redirect(route('crews.show', $crew));
        } catch (\InvalidArgumentException $e) {
            $this->addError('coordinatorAgentId', $e->getMessage());
        }
    }

    public function render()
    {
        $agents = Agent::where('status', AgentStatus::Active)->orderBy('name')->get();

        return view('livewire.crews.create-crew-form', [
            'agents' => $agents,
            'processTypes' => CrewProcessType::cases(),
        ])->layout('layouts.app', ['header' => 'Create Crew']);
    }
}
