<?php

namespace App\Livewire\Experiments;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use Illuminate\View\View;
use Livewire\Component;

class ExperimentNotebookPanel extends Component
{
    public string $experimentId;

    /** @var array<string, string> Pending annotation edits keyed by stage ID */
    public array $pendingAnnotations = [];

    /** Stage ID currently being edited */
    public ?string $editingStageId = null;

    public function mount(string $experimentId): void
    {
        Experiment::query()->findOrFail($experimentId);
        $this->experimentId = $experimentId;
    }

    public function startEditing(string $stageId): void
    {
        $this->editingStageId = $stageId;
        if (! isset($this->pendingAnnotations[$stageId])) {
            $stage = ExperimentStage::find($stageId);
            $this->pendingAnnotations[$stageId] = $stage->annotation ?? '';
        }
    }

    public function saveAnnotation(string $stageId): void
    {
        $this->authorize('edit-content');

        $stage = ExperimentStage::where('experiment_id', $this->experimentId)
            ->findOrFail($stageId);

        $stage->update(['annotation' => $this->pendingAnnotations[$stageId] ?? '']);

        $this->editingStageId = null;
        $this->dispatch('notify', type: 'success', message: 'Annotation saved.');
    }

    public function cancelEditing(): void
    {
        if ($this->editingStageId) {
            unset($this->pendingAnnotations[$this->editingStageId]);
        }
        $this->editingStageId = null;
    }

    public function render(): View
    {
        $stages = ExperimentStage::where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get();

        $stageRuns = AiRun::withoutGlobalScopes()
            ->where('experiment_id', $this->experimentId)
            ->whereNotNull('experiment_stage_id')
            ->select(['id', 'experiment_stage_id', 'model', 'prompt_tokens', 'completion_tokens', 'cost_credits', 'duration_ms', 'created_at'])
            ->get()
            ->groupBy('experiment_stage_id');

        return view('livewire.experiments.experiment-notebook-panel', [
            'stages' => $stages,
            'stageRuns' => $stageRuns,
        ]);
    }
}
