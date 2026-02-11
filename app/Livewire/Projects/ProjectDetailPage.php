<?php

namespace App\Livewire\Projects;

use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMilestone;
use App\Domain\Project\Models\ProjectRun;
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

    public function triggerRun(): void
    {
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

        return view('livewire.projects.project-detail-page', [
            'runs' => $runs,
            'milestones' => $milestones,
            'totalRuns' => $totalRuns,
            'successfulRuns' => $successfulRuns,
            'failedRuns' => $failedRuns,
        ])->layout('layouts.app', ['header' => $this->project->title]);
    }
}
