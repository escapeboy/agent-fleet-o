<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class MetricsPanel extends Component
{
    public Experiment $experiment;

    public function render()
    {
        $metrics = $this->experiment->metrics()
            ->orderBy('occurred_at', 'desc')
            ->get();

        $summary = $metrics->groupBy('type')->map(fn ($group) => [
            'count' => $group->count(),
            'avg' => round($group->avg('value'), 4),
            'sum' => round($group->sum('value'), 4),
            'min' => round($group->min('value'), 4),
            'max' => round($group->max('value'), 4),
        ]);

        return view('livewire.experiments.metrics-panel', [
            'metrics' => $metrics->take(50),
            'summary' => $summary,
        ]);
    }
}
