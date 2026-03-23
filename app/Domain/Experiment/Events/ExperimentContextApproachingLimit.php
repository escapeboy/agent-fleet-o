<?php

namespace App\Domain\Experiment\Events;

use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\DTOs\ContextHealthDTO;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an experiment's accumulated input tokens approach the model's context limit.
 *
 * Listeners can use this to: log/alert, build a handoff document artifact, or trigger
 * a context compaction step before the next pipeline stage.
 *
 * Thresholds: warning >= 80%, critical >= 90% of context window.
 */
class ExperimentContextApproachingLimit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Experiment $experiment,
        public readonly ContextHealthDTO $health,
    ) {}
}
