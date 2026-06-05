<?php

namespace App\Domain\Evaluation\Jobs;

use App\Domain\Evaluation\Actions\AppendRegressionCaseAction;
use App\Domain\Evaluation\Enums\EvaluationCaseSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background job that appends a deferred regression case at triage time
 * (Agentic AI Flywheel #1).
 */
class AppendRegressionCaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $input,
        public readonly ?string $failingOutput,
        public readonly string $errorModeLabel,
        public readonly string $source,
        public readonly array $metadata = [],
        public readonly ?string $expectedOutput = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(AppendRegressionCaseAction $action): void
    {
        $action->execute(
            teamId: $this->teamId,
            input: $this->input,
            failingOutput: $this->failingOutput,
            errorModeLabel: $this->errorModeLabel,
            source: EvaluationCaseSource::from($this->source),
            metadata: $this->metadata,
            expectedOutput: $this->expectedOutput,
        );
    }
}
