<?php

namespace App\Livewire\Workflows;

use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class WorkflowListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

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

    public function render()
    {
        $query = Workflow::query()
            ->withCount('nodes', 'experiments');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                  ->orWhere('description', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        return view('livewire.workflows.workflow-list-page', [
            'workflows' => $query->paginate(20),
            'statuses' => WorkflowStatus::cases(),
            'canCreate' => true,
        ])->layout('layouts.app', ['header' => 'Workflows']);
    }
}
