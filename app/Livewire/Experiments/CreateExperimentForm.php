<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Component;

class CreateExperimentForm extends Component
{
    public string $title = '';

    public string $thesis = '';

    public string $track = 'growth';

    public int $budgetCapCredits = 10000;

    public int $maxIterations = 3;

    public int $maxOutboundCount = 100;

    public string $workflowId = '';

    public bool $autoApprove = true;

    public string $successCriteria = '';

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'thesis' => 'required|string|max:1000',
            'track' => 'required|in:'.implode(',', array_column(ExperimentTrack::cases(), 'value')),
            'budgetCapCredits' => 'required|integer|min:100|max:1000000',
            'maxIterations' => 'required|integer|min:1|max:20',
            'maxOutboundCount' => 'required|integer|min:1|max:10000',
            'workflowId' => 'nullable|uuid',
            'successCriteria' => 'nullable|string|max:2000',
        ];
    }

    public function create(): void
    {
        $this->validate();

        $team = auth()->user()->currentTeam;

        $action = app(CreateExperimentAction::class);
        $experiment = $action->execute(
            userId: auth()->id(),
            title: $this->title,
            thesis: $this->thesis,
            track: $this->track,
            budgetCapCredits: $this->budgetCapCredits,
            maxIterations: $this->maxIterations,
            maxOutboundCount: $this->maxOutboundCount,
            constraints: ['auto_approve' => $this->autoApprove],
            successCriteria: $this->parseSuccessCriteria(),
            teamId: $team?->id,
            workflowId: $this->workflowId ?: null,
        );

        $this->redirect(route('experiments.show', $experiment), navigate: true);
    }

    private function parseSuccessCriteria(): array
    {
        if (empty(trim($this->successCriteria))) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $this->successCriteria)),
        ));
    }

    public function render()
    {
        return view('livewire.experiments.create-experiment-form', [
            'tracks' => ExperimentTrack::cases(),
            'workflows' => Workflow::where('status', WorkflowStatus::Active)->get(['id', 'name']),
            'canCreate' => true,
        ]);
    }
}
