<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class TransitionsLog extends Component
{
    public Experiment $experiment;

    public function render()
    {
        $transitions = $this->experiment->stateTransitions()
            ->with('actor')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('livewire.experiments.transitions-log', [
            'transitions' => $transitions,
        ]);
    }
}
