<?php

namespace App\Livewire\GitRepositories;

use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Models\GitRepository;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class GitRepositoryListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $modeFilter = '';

    #[Url]
    public string $providerFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedModeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedProviderFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = GitRepository::query()->orderBy('name');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('url', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->modeFilter) {
            $query->where('mode', $this->modeFilter);
        }

        if ($this->providerFilter) {
            $query->where('provider', $this->providerFilter);
        }

        return view('livewire.git-repositories.git-repository-list-page', [
            'repositories' => $query->paginate(20),
            'modes' => GitRepoMode::cases(),
            'providers' => GitProvider::cases(),
        ])->layout('layouts.app', ['header' => 'Git Repositories']);
    }
}
