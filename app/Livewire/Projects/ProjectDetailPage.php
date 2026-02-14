<?php

namespace App\Livewire\Projects;

use App\Domain\Credential\Models\Credential;
use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\RestartProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectDependency;
use App\Domain\Project\Models\ProjectMilestone;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class ProjectDetailPage extends Component
{
    public Project $project;

    public string $activeTab = 'activity';

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    public function pause(): void
    {
        app(PauseProjectAction::class)->execute($this->project, 'Manually paused');
        $this->project->refresh();
        session()->flash('message', 'Project paused.');
    }

    public function resume(): void
    {
        app(ResumeProjectAction::class)->execute($this->project);
        $this->project->refresh();
        session()->flash('message', 'Project resumed.');
    }

    public function archive(): void
    {
        app(ArchiveProjectAction::class)->execute($this->project);
        $this->project->refresh();
        session()->flash('message', 'Project archived.');
    }

    public function activate(): void
    {
        if ($this->project->status !== ProjectStatus::Draft) {
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

    public function restart(): void
    {
        app(RestartProjectAction::class)->execute($this->project);
        $this->project->refresh();
        session()->flash('message', 'Project restarted from scratch. New run triggered.');
    }

    public function triggerRun(): void
    {
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
        ])->layout('layouts.app', ['header' => $this->project->title]);
    }
}
