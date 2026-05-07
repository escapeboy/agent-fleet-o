<?php

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceReview;
use App\Domain\Marketplace\Models\MarketplaceUsageRecord;
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

        // Publisher analytics (visible to listing owner's team)
        $isPublisher = auth()->user()?->currentTeam?->id === $this->listing->team_id;

        $publisherStats = null;
        if ($isPublisher) {
            $recent = MarketplaceUsageRecord::withoutGlobalScopes()
                ->where('listing_id', $this->listing->id)
                ->where('executed_at', '>=', now()->subDays(30))
                ->selectRaw('status, COUNT(*) as count, SUM(cost_credits) as total_cost')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $publisherStats = [
                'run_count' => $this->listing->run_count,
                'success_count' => $this->listing->success_count,
                'success_rate' => $this->listing->run_count > 0
                    ? round(($this->listing->success_count / $this->listing->run_count) * 100, 1)
                    : null,
                'avg_cost_credits' => $this->listing->avg_cost_credits,
                'avg_duration_ms' => $this->listing->avg_duration_ms,
                'usage_trend' => $this->listing->usage_trend ?? [],
                'last_30d_runs' => (int) ($recent->get('completed')->count ?? 0),
                'last_30d_failures' => (int) ($recent->get('failed')->count ?? 0),
                'price_per_run' => $this->listing->price_per_run_credits,
                'monetization_enabled' => $this->listing->monetization_enabled,
            ];
        }

        return view('livewire.marketplace.marketplace-detail-page', [
            'reviews' => $reviews,
            'isPublisher' => $isPublisher,
            'publisherStats' => $publisherStats,
        ])->layout('layouts.app', ['header' => $this->listing->name]);
    }
}
