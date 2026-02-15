<?php

namespace App\Livewire\Projects;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectRunsTable extends Component
{
    use WithPagination;

    public Project $project;

    public string $statusFilter = '';

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = ProjectRun::where('project_id', $this->project->id)
            ->withCount('artifacts')
            ->orderByDesc('created_at');

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.projects.project-runs-table', [
            'runs' => $query->paginate(20),
        ]);
    }
}
