<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Jobs\ExecuteFlowEvaluationJob;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;

class RunFlowEvaluationAction
{
    /**
     * Create an evaluation run and dispatch the execution job.
     */
    public function execute(
        EvaluationDataset $dataset,
        string $workflowId,
        ?string $judgeModel = null,
        ?string $judgePrompt = null,
    ): EvaluationRun {
        $run = EvaluationRun::create([
            'team_id' => $dataset->team_id,
            'dataset_id' => $dataset->id,
            'workflow_id' => $workflowId,
            'status' => EvaluationStatus::Pending,
            'judge_model' => $judgeModel ?? 'claude-haiku-4-5-20251001',
            'judge_prompt' => $judgePrompt,
        ]);

        ExecuteFlowEvaluationJob::dispatch($run->id);

        return $run;
    }
}
