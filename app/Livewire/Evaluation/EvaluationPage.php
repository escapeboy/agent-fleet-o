<?php

namespace App\Livewire\Evaluation;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evaluation\Actions\CreateEvaluationDatasetAction;
use App\Domain\Evaluation\Actions\RunStructuredEvaluationAction;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;
use Livewire\Component;
use Livewire\WithPagination;

class EvaluationPage extends Component
{
    use WithPagination;

    public string $activeTab = 'runs';

    // Quick Evaluate form
    public bool $showEvalForm = false;

    public string $evalInput = '';

    public string $evalActualOutput = '';

    public string $evalExpectedOutput = '';

    public string $evalContext = '';

    public array $evalCriteria = [];

    public string $evalAgentId = '';

    public ?array $evalResult = null;

    public ?string $error = null;

    public ?string $success = null;

    // Create Dataset form
    public bool $showDatasetForm = false;

    public string $datasetName = '';

    public string $datasetDescription = '';

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function runEvaluation(): void
    {
        $this->validate([
            'evalInput' => ['required', 'string'],
            'evalActualOutput' => ['required', 'string'],
            'evalCriteria' => ['required', 'array', 'min:1'],
        ]);

        $this->error = null;
        $this->evalResult = null;

        try {
            $run = app(RunStructuredEvaluationAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                criteria: $this->evalCriteria,
                input: $this->evalInput,
                actualOutput: $this->evalActualOutput,
                expectedOutput: $this->evalExpectedOutput ?: null,
                context: $this->evalContext ?: null,
                agentId: $this->evalAgentId ?: null,
            );

            $this->evalResult = [
                'run_id' => $run->id,
                'scores' => $run->aggregate_scores,
                'total_cost_credits' => $run->total_cost_credits,
            ];
            $this->success = 'Evaluation completed.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function createDataset(): void
    {
        $this->validate([
            'datasetName' => ['required', 'string', 'max:255'],
            'datasetDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            app(CreateEvaluationDatasetAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                name: $this->datasetName,
                description: $this->datasetDescription ?: null,
            );

            $this->reset(['datasetName', 'datasetDescription']);
            $this->showDatasetForm = false;
            $this->success = 'Dataset created.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function deleteDataset(string $id): void
    {
        $dataset = EvaluationDataset::findOrFail($id);
        abort_unless($dataset->team_id === auth()->user()->current_team_id, 403);
        $dataset->delete();
        $this->success = 'Dataset deleted.';
    }

    public function render()
    {
        $runs = EvaluationRun::with(['dataset', 'agent'])
            ->latest()
            ->paginate(20);

        $datasets = EvaluationDataset::withCount('runs')
            ->latest()
            ->get();

        $criteria = array_keys(config('evaluation.criteria', []));

        $teamId = auth()->user()->current_team_id;

        $stats = [
            'runs_total' => EvaluationRun::where('team_id', $teamId)->count(),
            'runs_completed' => EvaluationRun::where('team_id', $teamId)->where('status', 'completed')->count(),
            'datasets' => EvaluationDataset::where('team_id', $teamId)->count(),
        ];

        return view('livewire.evaluation.evaluation-page', [
            'runs' => $runs,
            'datasets' => $datasets,
            'criteria' => $criteria,
            'agents' => Agent::orderBy('name')->pluck('name', 'id'),
            'stats' => $stats,
        ])->layout('layouts.app', ['header' => 'Evaluation']);
    }
}
