<?php

namespace App\Livewire\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Models\Memory;
use App\Domain\Project\Models\Project;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class MemoryBrowserPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $agentFilter = '';

    #[Url]
    public string $projectFilter = '';

    #[Url]
    public string $sourceTypeFilter = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public ?string $expandedId = null;

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

    public function updatedAgentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedProjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSourceTypeFilter(): void
    {
        $this->resetPage();
    }

    public function toggleExpand(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function deleteMemory(string $id): void
    {
        Memory::where('id', $id)->delete();

        if ($this->expandedId === $id) {
            $this->expandedId = null;
        }

        session()->flash('message', 'Memory deleted.');
    }

    public function render()
    {
        $query = Memory::query()->with(['agent', 'project']);

        if ($this->search) {
            $query->where('content', 'ilike', "%{$this->search}%");
        }

        if ($this->agentFilter) {
            $query->where('agent_id', $this->agentFilter);
        }

        if ($this->projectFilter) {
            $query->where('project_id', $this->projectFilter);
        }

        if ($this->sourceTypeFilter) {
            $query->where('source_type', $this->sourceTypeFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        return view('livewire.memory.memory-browser-page', [
            'memories' => $query->paginate(30),
            'agents' => Agent::orderBy('name')->pluck('name', 'id'),
            'projects' => Project::orderBy('name')->pluck('name', 'id'),
            'sourceTypes' => Memory::distinct()->pluck('source_type')->sort()->values(),
        ])->layout('layouts.app', ['header' => 'Memory Browser']);
    }
}
