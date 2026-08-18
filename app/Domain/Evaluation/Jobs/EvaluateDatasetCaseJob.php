<?php

namespace App\Domain\Evaluation\Jobs;

use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Jobs\Middleware\ApplyTenantTracer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Evaluate one dataset case as part of a parallel replay batch. Writes a single
 * `EvaluationRunResult` row via the shared action; the batch's `finally` callback
 * aggregates the run once every case job has settled.
 */
class EvaluateDatasetCaseJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  list<string>  $criteria
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $runId,
        public readonly string $caseId,
        public readonly string $targetProvider,
        public readonly string $targetModel,
        public readonly ?string $systemPrompt,
        public readonly array $criteria,
        public readonly string $judgeModel,
    ) {
        $this->onQueue('ai-calls');
    }

    public function middleware(): array
    {
        return [new ApplyTenantTracer];
    }

    public function handle(ReplayEvaluationDatasetAction $action): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = EvaluationRun::withoutGlobalScopes()->find($this->runId);
        $case = EvaluationCase::withoutGlobalScopes()->find($this->caseId);
        if ($run === null || $case === null) {
            return;
        }

        $action->evaluateCase(
            run: $run,
            case: $case,
            targetProvider: $this->targetProvider,
            targetModel: $this->targetModel,
            systemPrompt: $this->systemPrompt,
            criteria: $this->criteria,
            judgeModel: $this->judgeModel,
            teamId: $this->teamId,
        );
    }
}
