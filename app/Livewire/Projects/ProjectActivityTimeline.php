<?php

namespace App\Livewire\Projects;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Livewire\Component;

class ProjectActivityTimeline extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    public function render()
    {
        $recentRuns = ProjectRun::where('project_id', $this->project->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $isActive = in_array($this->project->status, [ProjectStatus::Active]);

        return view('livewire.projects.project-activity-timeline', [
            'recentRuns' => $recentRuns,
            'isActive' => $isActive,
        ]);
    }
}
