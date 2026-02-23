<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class OrchestrationTree extends Component
{
    public Experiment $experiment;

    public function mount(Experiment $experiment): void
    {
        $this->experiment = $experiment;
    }

    public function render()
    {
        // Find the root experiment (top of the tree)
        $root = $this->experiment;
        while ($root->parent_experiment_id) {
            $root = Experiment::withoutGlobalScopes()->find($root->parent_experiment_id) ?? $root;
            break; // Only go up one level for display
        }

        // Load the tree: root + children (up to 2 levels deep)
        $root->load(['children' => function ($q) {
            $q->withoutGlobalScopes()->with(['children' => function ($q2) {
                $q2->withoutGlobalScopes();
            }]);
        }]);

        return view('livewire.experiments.orchestration-tree', [
            'root' => $root,
            'isCurrentRoot' => $root->id === $this->experiment->id,
        ]);
    }
}
