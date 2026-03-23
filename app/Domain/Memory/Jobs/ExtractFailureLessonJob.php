<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Memory\Actions\ExtractFailureLessonAction;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background job to extract a failure lesson from a failed Experiment.
 *
 * Unique per experiment to prevent duplicate extractions if the experiment
 * transitions through multiple failure states.
 */
class ExtractFailureLessonJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        return "memory:failure_lesson:{$this->experimentId}";
    }

    public function handle(ExtractFailureLessonAction $action): void
    {
        $action->execute($this->experimentId, $this->teamId);
    }
}
