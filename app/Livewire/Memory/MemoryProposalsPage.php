<?php

namespace App\Livewire\Memory;

use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Focused review queue for agent-proposed memories.
 *
 * Surfaces only memories in the Proposed tier with no decision yet
 * (proposal_status IS NULL) so an admin can approve (promote to a curated
 * tier) or reject each one. The broader MemoryBrowserPage shows all tiers;
 * this page is the dedicated approve/reject inbox.
 */
class MemoryProposalsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public ?string $expandedId = null;

    /**
     * Selected approve-target tier per memory id. Defaults to canonical when
     * a row has not been touched.
     *
     * @var array<string, string>
     */
    public array $approveTier = [];

    /** Memory id currently showing its reject-reason input. */
    public ?string $rejectingId = null;

    public string $rejectReason = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleExpand(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    /**
     * Approve a proposal by promoting it to the given curated tier. Mirrors
     * the canonical approve path used by MemoryPromoteTool and the heuristic
     * AuditMemoryProposalsAction: bump tier + stamp proposal_status='approved'.
     */
    public function approve(string $memoryId): void
    {
        Gate::authorize('edit-content');

        $tier = MemoryTier::tryFrom($this->approveTier[$memoryId] ?? MemoryTier::Canonical->value);
        if (! $tier || ! $tier->isCurated()) {
            session()->flash('error', 'Invalid target tier.');

            return;
        }

        $memory = Memory::where('id', $memoryId)
            ->where('tier', MemoryTier::Proposed->value)
            ->whereNull('proposal_status')
            ->first();

        if (! $memory) {
            session()->flash('error', 'Proposal not found or already decided.');

            return;
        }

        $memory->update([
            'tier' => $tier->value,
            'proposal_status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => 'user:'.(auth()->user()?->email ?? 'anonymous'),
        ]);

        if ($this->expandedId === $memoryId) {
            $this->expandedId = null;
        }

        session()->flash('message', "Proposal approved and promoted to {$tier->value}.");
    }

    /** Reveal the reject-reason input for a given proposal. */
    public function startReject(string $memoryId): void
    {
        $this->rejectingId = $memoryId;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectReason = '';
    }

    /**
     * Reject a proposal: stamp proposal_status='rejected' with a reason so it
     * stops surfacing in retrieval and drops out of this queue.
     */
    public function reject(string $memoryId): void
    {
        Gate::authorize('edit-content');

        $reason = trim($this->rejectReason);
        if ($reason === '') {
            session()->flash('error', 'A rejection reason is required.');

            return;
        }

        $memory = Memory::where('id', $memoryId)
            ->where('tier', MemoryTier::Proposed->value)
            ->whereNull('proposal_status')
            ->first();

        if (! $memory) {
            session()->flash('error', 'Proposal not found or already decided.');

            return;
        }

        $memory->update([
            'proposal_status' => 'rejected',
            'reviewed_at' => now(),
            'rejection_reason' => mb_substr($reason, 0, 1000),
            'reviewed_by' => 'user:'.(auth()->user()?->email ?? 'anonymous'),
        ]);

        $this->rejectingId = null;
        $this->rejectReason = '';
        if ($this->expandedId === $memoryId) {
            $this->expandedId = null;
        }

        session()->flash('message', 'Proposal rejected.');
    }

    public function render(): View
    {
        $query = Memory::query()
            ->with(['agent', 'project'])
            ->where('tier', MemoryTier::Proposed->value)
            ->whereNull('proposal_status')
            ->orderBy('created_at', 'desc');

        if ($this->search !== '') {
            $query->where('content', 'ilike', "%{$this->search}%");
        }

        $curatedTiers = array_values(array_filter(
            MemoryTier::cases(),
            fn (MemoryTier $tier) => $tier->isCurated(),
        ));

        return view('livewire.memory.memory-proposals-page', [
            'proposals' => $query->paginate(25),
            'curatedTiers' => $curatedTiers,
        ])->layout('layouts.app', ['header' => 'Memory Proposals']);
    }
}
