<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class OutboundLog extends Component
{
    public Experiment $experiment;

    public function render()
    {
        $proposals = $this->experiment->outboundProposals()
            ->with('outboundActions')
            ->latest()
            ->get();

        return view('livewire.experiments.outbound-log', [
            'proposals' => $proposals,
        ]);
    }
}
