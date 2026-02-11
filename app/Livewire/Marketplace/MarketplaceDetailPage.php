<?php

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceReview;
use Livewire\Component;

class MarketplaceDetailPage extends Component
{
    public MarketplaceListing $listing;
    public string $activeTab = 'overview';
    public int $reviewRating = 5;
    public string $reviewComment = '';
    public bool $isInstalled = false;

    public function mount(MarketplaceListing $listing): void
    {
        $this->listing = $listing;
        $this->checkInstallStatus();
    }

    protected function checkInstallStatus(): void
    {
        $team = auth()->user()?->currentTeam;
        if ($team) {
            $this->isInstalled = $this->listing->installations()
                ->where('team_id', $team->id)
                ->exists();
        }
    }

    public function install(): void
    {
        $user = auth()->user();
        $team = $user->currentTeam;

        if (! $team) {
            session()->flash('error', 'You must belong to a team to install marketplace items.');
            return;
        }

        if ($this->isInstalled) {
            session()->flash('error', 'This item is already installed in your workspace.');
            return;
        }

        app(InstallFromMarketplaceAction::class)->execute($this->listing, $team->id, $user->id);

        $this->isInstalled = true;
        $this->listing->refresh();
        session()->flash('success', "{$this->listing->name} installed successfully!");
    }

    public function submitReview(): void
    {
        $this->validate([
            'reviewRating' => 'required|integer|min:1|max:5',
            'reviewComment' => 'nullable|string|max:2000',
        ]);

        $user = auth()->user();
        $team = $user->currentTeam;

        // Check if user already reviewed
        $existing = MarketplaceReview::where('listing_id', $this->listing->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            session()->flash('error', 'You have already reviewed this item.');
            return;
        }

        MarketplaceReview::create([
            'listing_id' => $this->listing->id,
            'user_id' => $user->id,
            'team_id' => $team?->id,
            'rating' => $this->reviewRating,
            'comment' => $this->reviewComment ?: null,
        ]);

        // Update listing aggregates
        $avg = MarketplaceReview::where('listing_id', $this->listing->id)->avg('rating');
        $count = MarketplaceReview::where('listing_id', $this->listing->id)->count();

        $this->listing->update([
            'avg_rating' => round($avg, 2),
            'review_count' => $count,
        ]);

        $this->listing->refresh();
        $this->reviewComment = '';
        $this->reviewRating = 5;

        session()->flash('success', 'Review submitted!');
    }

    public function render()
    {
        $reviews = $this->listing->reviews()->with('user')->latest()->get();

        return view('livewire.marketplace.marketplace-detail-page', [
            'reviews' => $reviews,
        ])->layout('layouts.app', ['header' => $this->listing->name]);
    }
}
