<?php

namespace App\Livewire\Outbound;

use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OutboundProposalsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    public ?string $expandedId = null;

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function toggle(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function render()
    {
        $query = OutboundProposal::query()
            ->with('experiment')
            ->latest();

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        $counts = [];
        foreach (OutboundProposalStatus::cases() as $case) {
            $counts[$case->value] = OutboundProposal::query()
                ->where('status', $case)
                ->count();
        }

        return view('livewire.outbound.outbound-proposals-page', [
            'proposals' => $query->paginate(25),
            'counts' => $counts,
            'statuses' => OutboundProposalStatus::cases(),
        ])->layout('layouts.app', ['header' => 'Outbound Proposals']);
    }
}
