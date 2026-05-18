<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Services\UnifiedTimelineService;
use Livewire\Component;

/**
 * Unified human + agent activity stream for an experiment.
 * Kanwas-inspired sprint — read-only; filtering is the only interaction.
 */
class UnifiedTimeline extends Component
{
    public Experiment $experiment;

    public ?string $kindFilter = null;

    public function render()
    {
        $kind = in_array($this->kindFilter, UnifiedTimelineService::KINDS, true)
            ? $this->kindFilter
            : null;

        $entries = app(UnifiedTimelineService::class)->build($this->experiment, 200, $kind);

        return view('livewire.experiments.unified-timeline', [
            'entries' => $entries,
        ]);
    }
}
