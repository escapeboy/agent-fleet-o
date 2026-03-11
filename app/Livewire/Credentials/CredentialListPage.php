<?php

namespace App\Livewire\Credentials;

use App\Domain\Credential\Enums\CredentialSource;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\TeamKmsConfig;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class CredentialListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $creatorSourceFilter = '';

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

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCreatorSourceFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Credential::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('description', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->typeFilter) {
            $query->where('credential_type', $this->typeFilter);
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->creatorSourceFilter) {
            $query->where('creator_source', $this->creatorSourceFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        $team = auth()->user()?->currentTeam;
        $kmsConfig = $team ? TeamKmsConfig::where('team_id', $team->id)->first() : null;

        return view('livewire.credentials.credential-list-page', [
            'credentials' => $query->paginate(20),
            'types' => CredentialType::cases(),
            'statuses' => CredentialStatus::cases(),
            'creatorSources' => CredentialSource::cases(),
            'team' => $team,
            'kmsConfig' => $kmsConfig,
            'canCreate' => true,