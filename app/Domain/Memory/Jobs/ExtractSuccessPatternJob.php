<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Memory\Actions\ExtractSuccessPatternAction;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background job to extract a success pattern from a completed Experiment.
 *
 * Unique per experiment to prevent duplicate extractions if the experiment
 * emits multiple Completed transitions (e.g. after a retry that succeeds).
 */
class ExtractSuccessPatternJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 300; // 5-minute window

    public function __construct(
        public readonly string $experimentId,
        public readonly string $teamId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "memory:success_pattern:{$this->experimentId}";
    }

    public function handle(ExtractSuccessPatternAction $action): void
    {
        $action->execute($this->experimentId, $this->teamId);
    }
}
