<?php

namespace App\Livewire\Projects;

use App\Domain\Credential\Models\Credential;
use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\CreateProjectSnapshotAction;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\RestartProjectAction;
use App\Domain\Project\Actions\RestoreProjectSnapshotAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\DTOs\ProjectHealthReport;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectDependency;
use App\Domain\Project\Models\ProjectMilestone;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Project\Models\ProjectSnapshot;
use App\Domain\Project\Services\ProjectHealthEvaluator;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ProjectDetailPage extends Component
{
    public Project $project;

    public string $activeTab = 'activity';

    public string $snapshotLabel = '';

    public ?string $confirmingRestoreId = null;

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    #[Computed]
    public function health(): ProjectHealthReport
    {
        return app(ProjectHealthEvaluator::class)->evaluate($this->project);
    }

    /**
     * The status may have changed between render and click (stale page,
     * double click), so invalid transitions are flashed as an error.
     */
    public function pause(): void
    {
        Gate::authorize('edit-content');

        try {
            app(PauseProjectAction::class)->execute($this->project, 'Manually paused');
        } catch (\InvalidArgumentException $e) {
            $this->project->refresh();
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->project->refresh();
        session()->flash('message', 'Project paused.');
    }

    /**
     * Resume only applies to paused projects; anything else (including a
     * status change since render) is flashed as an error.
     */
    public function resume(): void
    {
        Gate::authorize('edit-content');

        try {
            $this->project->refresh();
            $this->ensurePaused($this->project);
            app(ResumeProjectAction::class)->execute($this->project);
        } catch (\InvalidArgumentException $e) {
            $this->project->refresh();
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->project->refresh();
        session()->flash('message', 'Project resumed.');
    }

    /**
     * Resume only applies to paused projects (the UI only offers it there).
     *
     * @throws \InvalidArgumentException
     */
    private function ensurePaused(Project $project): void
    {
        if ($project->status !== ProjectStatus::Paused) {
            throw new \InvalidArgumentException("Cannot resume project in {$project->status->value} state.");
        }
    }

    public function archive(): void
    {
        Gate::authorize('edit-content');

        app(ArchiveProjectAction::class)->execute($this->project);
        $this->project->refresh();
        session()->flash('message', 'Project archived.');
    }

    public function activate(): void
    {
        Gate::authorize('edit-content');

        $this->project->refresh();

        if ($this->project->status !== ProjectStatus::Draft) {
            session()->flash('error', "Project can only be activated from draft (current status: {$this->project->status->label()}).");

            return;
        }

        $this->project->update([
            'status' => ProjectStatus::Active,
            'started_at' => now(),
        ]);

        // One-shot projects always trigger immediately on activation
        // Continuous projects trigger if schedule has run_immediately
        if ($this->project->type === ProjectType::OneShot) {
            app(TriggerProjectRunAction::class)->execute($this->project->fresh(), 'initial');
        } elseif ($this->project->schedule?->run_immediately) {
            app(TriggerProjectRunAction::class)->execute($this->project->fresh(), 'initial');
        }

        $this->project->refresh();
        session()->flash('message', 'Project activated!');
    }

    /**
     * The status may have changed between render and click (stale page,
     * double click), so invalid transitions are flashed as an error.
     */
    public function restart(): void
    {
        Gate::authorize('edit-content');

        try {
            $this->project->refresh();
            app(RestartProjectAction::class)->execute($this->project);
        } catch (\InvalidArgumentException $e) {
            $this->project->refresh();
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->project->refresh();
        session()->flash('message', 'Project restarted from scratch. New run triggered.');
    }

    public function triggerRun(): void
    {
        Gate::authorize('edit-content');

        $key = 'trigger-run:'.$this->project->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            session()->flash('message', "Too many triggers. Please wait {$seconds} seconds.");

            return;
        }

        RateLimiter::hit($key, 60);

        app(TriggerProjectRunAction::class)->execute($this->project);
        $this->project->refresh();
        session()->flash('message', 'New run triggered.');
    }

    public function createSnapshot(): void
    {
        Gate::authorize('edit-content');

        app(CreateProjectSnapshotAction::class)->execute(
            project: $this->project,
            label: $this->snapshotLabel ?: null,
            createdBy: auth()->id(),
        );

        $this->snapshotLabel = '';
        session()->flash('message', 'Snapshot created.');
    }

    public function confirmRestore(string $snapshotId): void
    {
        $this->confirmingRestoreId = $snapshotId;
    }

    public function cancelRestore(): void
    {
        $this->confirmingRestoreId = null;
    }

    public function restoreSnapshot(string $snapshotId): void
    {
        Gate::authorize('edit-content');

        $snapshot = ProjectSnapshot::where('project_id', $this->project->id)
            ->findOrFail($snapshotId);

        $result = app(RestoreProjectSnapshotAction::class)->execute($snapshot, auth()->id());

        $this->confirmingRestoreId = null;
        $this->project->refresh();

        session()->flash('message', $result['restored']
            ? 'Project configuration restored from snapshot.'
            : $result['reason']);
    }

    public function render()
    {
        $this->project->load('schedule');

        $runs = ProjectRun::where('project_id', $this->project->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $milestones = ProjectMilestone::where('project_id', $this->project->id)
            ->orderBy('sort_order')
            ->get();

        $totalRuns = ProjectRun::where('project_id', $this->project->id)->count();
        $successfulRuns = ProjectRun::where('project_id', $this->project->id)->where('status', 'completed')->count();
        $failedRuns = ProjectRun::where('project_id', $this->project->id)->where('status', 'failed')->count();

        $upstreamDeps = ProjectDependency::where('project_id', $this->project->id)
            ->with('dependsOn')
            ->ordered()
            ->get();

        $downstreamDeps = ProjectDependency::where('depends_on_id', $this->project->id)
            ->with('project')
            ->get();

        $allowedTools = ! empty($this->project->allowed_tool_ids)
            ? Tool::withoutGlobalScopes()->whereIn('id', $this->project->allowed_tool_ids)->get()
            : collect();

        $allowedCredentials = ! empty($this->project->allowed_credential_ids)
            ? Credential::withoutGlobalScopes()->whereIn('id', $this->project->allowed_credential_ids)->get()
            : collect();

        $snapshots = $this->activeTab === 'snapshots'
            ? ProjectSnapshot::where('project_id', $this->project->id)
                ->with('creator:id,name')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
            : collect();

        $snapshotCount = ProjectSnapshot::where('project_id', $this->project->id)->count();

        return view('livewire.projects.project-detail-page', [
            'runs' => $runs,
            'milestones' => $milestones,
            'totalRuns' => $totalRuns,
            'successfulRuns' => $successfulRuns,
            'failedRuns' => $failedRuns,
            'upstreamDeps' => $upstreamDeps,
            'downstreamDeps' => $downstreamDeps,
            'allowedTools' => $allowedTools,
            'allowedCredentials' => $allowedCredentials,
            'snapshots' => $snapshots,
            'snapshotCount' => $snapshotCount,
        ])->layout('layouts.app', ['header' => $this->project->title]);
    }
}
