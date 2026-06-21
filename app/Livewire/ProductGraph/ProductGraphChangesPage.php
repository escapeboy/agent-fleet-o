<?php

namespace App\Livewire\ProductGraph;

use App\Domain\ProductGraph\Actions\ReviewChangeAction;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductGraphChangesPage extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $statusFilter = 'pending';

    public ?string $error = null;

    public ?string $success = null;

    private function teamId(): string
    {
        return auth()->user()->current_team_id;
    }

    public function approve(string $id): void
    {
        $this->review($id, true);
    }

    public function reject(string $id): void
    {
        $this->review($id, false);
    }

    private function review(string $id, bool $approve): void
    {
        $this->authorize('edit-content');

        $change = ProductGraphChange::withoutGlobalScopes()
            ->where('team_id', $this->teamId())
            ->find($id);

        if (! $change) {
            $this->error = 'Change not found.';

            return;
        }

        try {
            app(ReviewChangeAction::class)->execute(
                change: $change,
                approve: $approve,
                reviewerUserId: auth()->id(),
            );
            $this->success = $approve ? 'Change approved and applied.' : 'Change rejected.';
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        if (! config('productgraph.enabled')) {
            return view('livewire.product-graph.product-graph-changes-page', ['disabled' => true]);
        }

        $changes = ProductGraphChange::withoutGlobalScopes()
            ->where('team_id', $this->teamId())
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->latest()
            ->limit(100)
            ->get();

        return view('livewire.product-graph.product-graph-changes-page', [
            'disabled' => false,
            'changes' => $changes,
        ]);
    }
}
