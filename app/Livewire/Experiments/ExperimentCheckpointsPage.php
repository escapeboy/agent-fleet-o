<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Actions\ResumeFromCheckpointAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Standalone read-mostly page that surfaces an experiment's playbook-step
 * checkpoints (checkpoint_data / worker_id / idempotency_key / version /
 * heartbeat) and offers a single "resume from checkpoint" action.
 *
 * Resume delegates to ResumeFromCheckpointAction, which always resumes from the
 * MOST RECENT checkpoint — the backend has no notion of restoring an arbitrary
 * historical checkpoint, so the per-row data is informational and there is one
 * page-level restore button rather than a per-row one.
 *
 * Uses `public string $experimentId` + a computed property (NOT route-model
 * binding) to avoid the Postgres 22P02 cast error on a missing/foreign UUID.
 */
class ExperimentCheckpointsPage extends Component
{
    public string $experimentId;

    public bool $showResumeConfirm = false;

    public function mount(string $experiment): void
    {
        $this->experimentId = $experiment;

        // Resolve once so an out-of-team / missing id 404s on first load
        // rather than rendering an empty page.
        $this->experiment();
    }

    #[Computed]
    public function experiment(): Experiment
    {
        $teamId = auth()->user()?->current_team_id;

        $experiment = Experiment::query()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->find($this->experimentId);

        abort_if($experiment === null, 404);

        return $experiment;
    }

    /**
     * Steps that carry checkpoint data, most-recently-updated first.
     *
     * @return Collection<int, PlaybookStep>
     */
    #[Computed]
    public function checkpoints(): Collection
    {
        return PlaybookStep::query()
            ->where('experiment_id', $this->experiment()->id)
            ->whereNotNull('checkpoint_data')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function resumeFromCheckpoint(): void
    {
        Gate::authorize('edit-content');

        $result = app(ResumeFromCheckpointAction::class)->execute($this->experiment());

        unset($this->experiment, $this->checkpoints);
        $this->showResumeConfirm = false;

        session()->flash($result['resumed'] ? 'message' : 'error', $result['message']);
    }

    public function render(): View
    {
        return view('livewire.experiments.experiment-checkpoints-page')
            ->layout('layouts.app', ['header' => 'Checkpoints — '.$this->experiment()->title]);
    }
}
