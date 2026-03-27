<?php

namespace App\Livewire\Evolution;

use App\Domain\Evolution\Actions\ApplyEvolutionProposalAction;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EvolutionListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $statusFilter = 'pending';

    #[Url]
    public string $agentFilter = '';

    public ?string $error = null;

    public ?string $success = null;

    public function approve(string $proposalId): void
    {
        $proposal = EvolutionProposal::findOrFail($proposalId);

        $proposal->update([
            'status' => EvolutionProposalStatus::Approved,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->success = 'Proposal approved. You can now apply it.';
        $this->error = null;
    }

    public function reject(string $proposalId): void
    {
        $proposal = EvolutionProposal::findOrFail($proposalId);

        $proposal->update([
            'status' => EvolutionProposalStatus::Rejected,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->success = 'Proposal rejected.';
        $this->error = null;
    }

    public function apply(string $proposalId): void
    {
        $this->error = null;

        try {
            $proposal = EvolutionProposal::findOrFail($proposalId);

            app(ApplyEvolutionProposalAction::class)->execute($proposal, auth()->id());

            $this->success = 'Evolution applied to agent successfully.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function setStatus(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
        $this->success = null;
        $this->error = null;
    }

    public function render()
    {
        $query = EvolutionProposal::with(['agent', 'reviewer'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->agentFilter, fn ($q) => $q->where('agent_id', $this->agentFilter))
            ->orderByDesc('created_at');

        $counts = [
            'pending' => EvolutionProposal::where('status', EvolutionProposalStatus::Pending)->count(),
            'approved' => EvolutionProposal::where('status', EvolutionProposalStatus::Approved)->count(),
            'applied' => EvolutionProposal::where('status', EvolutionProposalStatus::Applied)->count(),
            'rejected' => EvolutionProposal::where('status', EvolutionProposalStatus::Rejected)->count(),
        ];

        $agents = EvolutionProposal::with('agent')
            ->select('agent_id')
            ->distinct()
            ->get()
            ->pluck('agent')
            ->filter()
            ->unique('id');

        return view('livewire.evolution.evolution-list-page', [
            'proposals' => $query->paginate(20),
            'counts' => $counts,
            'agents' => $agents,
        ])->layout('layouts.app', ['header' => 'Evolution Proposals']);
    }
}
