<?php

namespace App\Livewire\Crews;

use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class CrewListPage extends Component
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
        $query = Crew::query()
            ->withCount(['members', 'executions']);

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

        return view('livewire.crews.crew-list-page', [
            'crews' => $query->paginate(20),
            'statuses' => CrewStatus::cases(),
        ])->layout('layouts.app', ['header' => 'Crews']);
    }
}
