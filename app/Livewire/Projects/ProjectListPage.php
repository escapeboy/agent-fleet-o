<?php

namespace App\Livewire\Projects;

use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $typeFilter = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function pause(string $projectId): void
    {
        $project = Project::findOrFail($projectId);
        app(PauseProjectAction::class)->execute($project, 'Manually paused from dashboard');
        session()->flash('message', "Project \"{$project->title}\" paused.");
    }

    public function resume(string $projectId): void
    {
        $project = Project::findOrFail($projectId);
        app(ResumeProjectAction::class)->execute($project);
        session()->flash('message', "Project \"{$project->title}\" resumed.");
    }

    public function archive(string $projectId): void
    {
        $project = Project::findOrFail($projectId);
        app(ArchiveProjectAction::class)->execute($project);
        session()->flash('message', "Project \"{$project->title}\" archived.");
    }

    public function render()
    {
        $query = Project::query()->with('schedule');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'ilike', "%{$this->search}%")
                    ->orWhere('description', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        return view('livewire.projects.project-list-page', [
            'projects' => $query->paginate(20),
            'statuses' => ProjectStatus::cases(),
            'types' => ProjectType::cases(),
        ])->layout('layouts.app', ['header' => 'Projects']);
    }
}
