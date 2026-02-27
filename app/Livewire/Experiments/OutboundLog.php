<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class OutboundLog extends Component
{
    public Experiment $experiment;

    public ?string $expandedProposalId = null;

    public function toggleProposal(string $id): void
    {
        $this->expandedProposalId = $this->expandedProposalId === $id ? null : $id;
    }

    public function render()
    {
        $proposals = $this->experiment->outboundProposals()
            ->with(['outboundActions' => fn ($q) => $q->orderBy('created_at')])
            ->latest()
            ->get();

        return view('livewire.experiments.outbound-log', [
            'proposals' => $proposals,
        ]);
    }
}
