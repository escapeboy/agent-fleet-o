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
        // Walk to root via TeamScope so cross-team parents are invisible.
        // The previous withoutGlobalScopes() variant disclosed parent and
        // descendant experiment names from other teams whenever a child
        // happened to point at a foreign-team ancestor.
        $root = $this->experiment;
        while ($root->parent_experiment_id) {
            $parent = Experiment::query()->find($root->parent_experiment_id);
            if (! $parent) {
                break;
            }
            $root = $parent;
            break; // Only go up one level for display
        }

        // Load the tree: root + children (up to 2 levels deep). TeamScope
        // applies via the relationship's eager-load query.
        $root->load(['children' => function ($q) {
            $q->with('children');
        }]);

        return view('livewire.experiments.orchestration-tree', [
            'root' => $root,
            'isCurrentRoot' => $root->id === $this->experiment->id,
        ]);
    }
}
