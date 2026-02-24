<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Workflow\Models\WorkflowNodeEvent;
use Livewire\Component;

class ExecutionChainPanel extends Component
{
    public Experiment $experiment;

    public function render()
    {
        $events = WorkflowNodeEvent::where('experiment_id', $this->experiment->id)
            ->orderBy('created_at')
            ->get();

        $stats = [
            'total' => $events->count(),
            'completed' => $events->where('event_type', 'completed')->count(),
            'failed' => $events->where('event_type', 'failed')->count(),
            'waiting_time' => $events->where('event_type', 'waiting_time')->count(),
            'total_duration_ms' => $events->sum('duration_ms'),
        ];

        return view('livewire.experiments.execution-chain-panel', [
            'events' => $events,
            'stats' => $stats,
        ]);
    }

    public function formatDuration(int $ms): string
    {
        if ($ms < 1000) {
            return $ms.'ms';
        }

        if ($ms < 60_000) {
            return round($ms / 1000, 1).'s';
        }

        $minutes = floor($ms / 60_000);
        $seconds = round(($ms % 60_000) / 1000);

        return "{$minutes}m {$seconds}s";
    }
}
