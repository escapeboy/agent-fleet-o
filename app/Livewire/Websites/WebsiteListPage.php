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

    public ?string $progressWebsiteId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openProgress(string $websiteId): void
    {
        $this->progressWebsiteId = $websiteId;
    }

    public function closeProgress(): void
    {
        $this->progressWebsiteId = null;
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

        $query->orderBy('created_at', 'desc');

        $websites = $query->paginate(20);

        // Check all pages, not just the current paginated page, so wire:poll activates correctly
        $hasGenerating = Website::where('status', WebsiteStatus::Generating)->exists();

        $progressExecution = null;
        if ($this->progressWebsiteId) {
            // TeamScope global scope is active here, so only the current team's websites are visible
            $progressWebsite = Website::with('crewExecution.taskExecutions')
                ->find($this->progressWebsiteId);
            $progressExecution = $progressWebsite?->crewExecution;
        }

        return view('livewire.websites.website-list-page', [
            'websites' => $websites,
            'statuses' => WebsiteStatus::cases(),
            'hasGenerating' => $hasGenerating,
            'progressExecution' => $progressExecution,
        ])->layout('layouts.app', ['header' => 'Websites']);
    }
}
