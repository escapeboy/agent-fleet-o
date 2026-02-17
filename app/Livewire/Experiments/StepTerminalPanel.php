<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Services\StepOutputBroadcaster;
use Livewire\Component;

class StepTerminalPanel extends Component
{
    public string $stepId;

    public string $output = '';

    public function pollOutput(): void
    {
        $broadcaster = app(StepOutputBroadcaster::class);
        $accumulated = $broadcaster->getAccumulatedOutput($this->stepId);

        if ($accumulated && $accumulated !== $this->output) {
            $this->output = $accumulated;
            $this->dispatch('terminal-output-'.$this->stepId, output: $this->output);
        }
    }

    public function render()
    {
        return view('livewire.experiments.step-terminal-panel');
    }
}
