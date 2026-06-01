<?php

namespace App\Livewire\Metrics;

use App\Domain\Metrics\Services\RocsCalculator;
use Livewire\Component;

class RocsPage extends Component
{
    public string $timeWindow = '30d';

    public function updatedTimeWindow(): void
    {
        // Triggers re-render
    }

    public function render(RocsCalculator $calculator)
    {
        $cutoff = match ($this->timeWindow) {
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            '90d' => now()->subMonths(3),
            default => now()->subMonth(),
        };

        $report = $calculator->forTeam(auth()->user()->currentTeam->id, $cutoff);

        return view('livewire.metrics.rocs-page', [
            'summary' => $report['summary'],
            'byExperiment' => $report['by_experiment'],
            'byAgent' => $report['by_agent'],
        ])->layout('layouts.app', ['header' => 'Return on Cognitive Spend']);
    }
}
