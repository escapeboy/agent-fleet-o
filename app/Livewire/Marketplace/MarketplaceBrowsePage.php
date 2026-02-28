<?php

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Skill\Services\SkillCompatibilityChecker;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class MarketplaceBrowsePage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $categoryFilter = '';

    #[Url]
    public string $pricingFilter = '';

    public string $sortField = 'install_count';

    public string $sortDirection = 'desc';

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'desc';
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

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPricingFilter(): void
    {
        $this->resetPage();
    }

    public function install(string $listingId): void
    {
        $listing = MarketplaceListing::findOrFail($listingId);
        $user = auth()->user();
        $team = $user->currentTeam;

        if (! $team) {
            session()->flash('error', 'You must belong to a team to install marketplace items.');

            return;
        }

        // Check if already installed
        $alreadyInstalled = $listing->installations()
            ->where('team_id', $team->id)
            ->exists();

        if ($alreadyInstalled) {
            session()->flash('error', 'This item is already installed in your workspace.');

            return;
        }

        // Team-private listings can only be installed by the owning team
        if ($listing->visibility === ListingVisibility::Team && $listing->team_id !== $team->id) {
            session()->flash('error', 'This item is private to its team.');

            return;
        }

        app(InstallFromMarketplaceAction::class)->execute($listing, $team->id, $user->id);

        session()->flash('success', "{$listing->name} installed successfully!");
    }

    public function render()
    {
        $teamId = auth()->user()?->currentTeam?->id;

        $query = MarketplaceListing::query()
            ->where('status', MarketplaceStatus::Published)
            ->where(function ($q) use ($teamId) {
                $q->where('visibility', ListingVisibility::Public);
                if ($teamId) {
                    $q->orWhere(fn ($q2) => $q2
                        ->where('visibility', ListingVisibility::Team)
                        ->where('team_id', $teamId)
                    );
                }
            });

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('description', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        if ($this->categoryFilter) {
            $query->where('category', $this->categoryFilter);
        }

        if ($this->pricingFilter === 'free') {
            $query->where(function ($q) {
                $q->where('monetization_enabled', false)->orWhere('price_per_run_credits', 0);
            });
        } elseif ($this->pricingFilter === 'paid') {
            $query->where('monetization_enabled', true)->where('price_per_run_credits', '>', 0);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        $categories = MarketplaceListing::query()
            ->where('status', MarketplaceStatus::Published)
            ->where(function ($q) use ($teamId) {
                $q->where('visibility', ListingVisibility::Public);
                if ($teamId) {
                    $q->orWhere(fn ($q2) => $q2
                        ->where('visibility', ListingVisibility::Team)
                        ->where('team_id', $teamId)
                    );
                }
            })
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        $team = auth()->user()?->currentTeam;
        if ($team) {
            $availableProviders = app(SkillCompatibilityChecker::class)->getAvailableProviders($team);
        } else {
            $availableProviders = [];
        }

        return view('livewire.marketplace.marketplace-browse-page', [
            'listings' => $query->paginate(12),
            'categories' => $categories,
            'availableProviders' => $availableProviders,
        ])->layout('layouts.app', ['header' => 'Marketplace']);
    }
}
