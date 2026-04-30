<?php

namespace App\Domain\Evaluation\Jobs;

use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Jobs\Middleware\ApplyTenantTracer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReplayEvaluationDatasetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param  list<string>  $criteria
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $datasetId,
        public readonly string $targetProvider,
        public readonly string $targetModel,
        public readonly ?string $systemPrompt = null,
        public readonly array $criteria = ['correctness', 'relevance'],
        public readonly ?string $judgeModel = null,
        public readonly int $maxCases = 100,
    ) {
        $this->onQueue('ai-calls');
    }

    public function middleware(): array
    {
        return [new ApplyTenantTracer];
    }

    public function handle(ReplayEvaluationDatasetAction $action): void
    {
        try {
            $action->execute(
                teamId: $this->teamId,
                datasetId: $this->datasetId,
                targetProvider: $this->targetProvider,
                targetModel: $this->targetModel,
                systemPrompt: $this->systemPrompt,
                criteria: $this->criteria,
                judgeModel: $this->judgeModel,
                maxCases: $this->maxCases,
            );
        } catch (\Throwable $e) {
            Log::error('ReplayEvaluationDatasetJob failed', [
                'team_id' => $this->teamId,
                'dataset_id' => $this->datasetId,
                'error' => $e->getMessage(),
            ]);
            // Leave run in Running status; operator can inspect logs. Do not silently mark Failed
            // because the failure may be transient and reruns won't create duplicate results.
            throw $e;
        }
    }
}
