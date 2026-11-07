<?php

namespace App\Livewire\Projects;

use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Livewire\Concerns\HasAssistantSelection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectListPage extends Component
{
    use HasAssistantSelection;
    use WithPagination;

    public function mount(): void
    {
        $this->selectionKind = 'project';
    }

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
        Gate::authorize('edit-content');

        $project = Project::findOrFail($projectId);

        $this->runTransition(
            fn () => app(PauseProjectAction::class)->execute($project, 'Manually paused from dashboard'),
            "Project \"{$project->title}\" paused.",
        );
    }

    public function resume(string $projectId): void
    {
        Gate::authorize('edit-content');

        $project = Project::findOrFail($projectId);

        if ($project->status !== ProjectStatus::Paused) {
            session()->flash('error', "Cannot resume project in {$project->status->value} state.");

            return;
        }

        $this->runTransition(
            fn () => app(ResumeProjectAction::class)->execute($project),
            "Project \"{$project->title}\" resumed.",
        );
    }

    /**
     * Run a lifecycle action, turning a rejected transition (stale page, double click)
     * into an error flash instead of an exception.
     */
    private function runTransition(callable $transition, string $successMessage): void
    {
        try {
            $transition();
            session()->flash('message', $successMessage);
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function archive(string $projectId): void
    {
        Gate::authorize('edit-content');

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
            'canCreate' => true,
        ])->layout('layouts.app', ['header' => 'Projects']);
    }
}
