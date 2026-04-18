<?php

namespace App\Livewire\Workflows;

use App\Domain\Evaluation\Actions\CreateFlowEvaluationDatasetAction;
use App\Domain\Evaluation\Actions\RunFlowEvaluationAction;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Component;
use Livewire\WithPagination;

class EvaluationListPage extends Component
{
    use WithPagination;

    public bool $showCreateForm = false;

    public string $datasetName = '';

    public string $datasetDescription = '';

    public string $datasetWorkflowId = '';

    public ?string $error = null;

    public ?string $success = null;

    public function createDataset(): void
    {
        $this->validate([
            'datasetName' => ['required', 'string', 'max:255'],
            'datasetDescription' => ['nullable', 'string', 'max:1000'],
            'datasetWorkflowId' => ['nullable', 'uuid'],
        ]);

        $this->error = null;

        try {
            app(CreateFlowEvaluationDatasetAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                name: $this->datasetName,
                description: $this->datasetDescription ?: null,
                workflowId: $this->datasetWorkflowId ?: null,
            );

            $this->reset(['datasetName', 'datasetDescription', 'datasetWorkflowId', 'showCreateForm']);
            $this->success = 'Dataset created.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function startRun(string $datasetId): void
    {
        $dataset = EvaluationDataset::findOrFail($datasetId);
        abort_unless($dataset->team_id === auth()->user()->current_team_id, 403);

        if (! $dataset->workflow_id) {
            $this->error = 'Dataset has no associated workflow.';

            return;
        }

        try {
            app(RunFlowEvaluationAction::class)->execute(
                dataset: $dataset,
                workflowId: $dataset->workflow_id,
            );

            $this->success = 'Evaluation run started.';
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
        $datasets = EvaluationDataset::with(['workflow'])
            ->withCount('runs')
            ->latest()
            ->paginate(20);

        // Attach latest run summary to each dataset
        $runsByDataset = EvaluationRun::whereIn('dataset_id', $datasets->pluck('id'))
            ->whereNotNull('summary')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('dataset_id');

        return view('livewire.workflows.evaluation-list-page', [
            'datasets' => $datasets,
            'runsByDataset' => $runsByDataset,
            'workflows' => Workflow::orderBy('name')->pluck('name', 'id'),
        ])->layout('layouts.app', ['header' => 'Flow Evaluations']);
    }
}
