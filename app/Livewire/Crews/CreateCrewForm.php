<?php

namespace App\Livewire\Crews;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Actions\GenerateCrewFromPromptAction;
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

    /**
     * Per-worker constraint overrides keyed by agent_id.
     * Each entry may contain: tool_allowlist (comma-separated string), max_steps (int), max_credits (int).
     *
     * @var array<string, array{tool_allowlist: string, max_steps: string, max_credits: string}>
     */
    public array $workerConstraints = [];

    public int $maxTaskIterations = 3;

    public float $qualityThreshold = 0.70;

    public string $convergenceMode = 'any_validated';

    public float $minValidatedRatio = 1.0;

    // AI Generate from prompt
    public bool $showGenerateModal = false;

    public string $generatePrompt = '';

    public bool $generating = false;

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

    public function generateFromPrompt(GenerateCrewFromPromptAction $action): void
    {
        $this->validate(['generatePrompt' => 'required|string|min:10']);

        $this->generating = true;

        try {
            $result = $action->execute(
                goal: $this->generatePrompt,
                teamId: auth()->user()->current_team_id,
            );

            $this->name = $result['crew_name'] ?? '';
            $this->description = $result['description'] ?? '';
            $this->processType = $result['process_type'] ?? 'hierarchical';
            $this->qualityThreshold = (float) ($result['suggested_quality_threshold'] ?? 0.70);
            $this->showGenerateModal = false;

            session()->flash('message', 'Crew structure generated. Review the form and add your agents.');
        } finally {
            $this->generating = false;
        }
    }

    public function toggleWorker(string $agentId): void
    {
        if (in_array($agentId, $this->workerAgentIds)) {
            $this->workerAgentIds = array_values(array_diff($this->workerAgentIds, [$agentId]));
            unset($this->workerConstraints[$agentId]);
        } else {
            $this->workerAgentIds[] = $agentId;
            // Initialise constraint slots so wire:model binds correctly.
            $this->workerConstraints[$agentId] ??= ['tool_allowlist' => '', 'max_steps' => '', 'max_credits' => ''];
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
                workerConstraints: $this->workerConstraints,
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
