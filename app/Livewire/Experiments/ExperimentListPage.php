<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ExperimentListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $trackFilter = '';

    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';

    public bool $showCreateForm = false;

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

    public function updatedTrackFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Experiment::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'ilike', "%{$this->search}%")
                  ->orWhere('thesis', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->trackFilter) {
            $query->where('track', $this->trackFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        return view('livewire.experiments.experiment-list-page', [
            'experiments' => $query->paginate(20),
            'statuses' => ExperimentStatus::cases(),
            'tracks' => ExperimentTrack::cases(),
        ])->layout('layouts.app', ['header' => 'Experiments']);
    }
}
