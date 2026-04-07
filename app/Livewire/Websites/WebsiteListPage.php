<?php

namespace App\Livewire\Websites;

use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class WebsiteListPage extends Component
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
        $query = Website::query()->withCount('pages');

        if ($this->search) {
            $query->where('name', 'ilike', "%{$this->search}%");
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        return view('livewire.websites.website-list-page', [
            'websites' => $query->paginate(15),
            'statuses' => WebsiteStatus::cases(),
        ])->layout('layouts.app', ['header' => 'Websites']);
    }
}
