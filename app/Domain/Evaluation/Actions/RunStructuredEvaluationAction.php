<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationScore;
use App\Domain\Evaluation\Services\LlmJudge;
use Illuminate\Support\Facades\Log;

class RunStructuredEvaluationAction
{
    public function __construct(
        private readonly LlmJudge $judge,
    ) {}

    /**
     * Run a structured multi-criteria evaluation.
     *
     * @param  array<string>  $criteria  Criterion names (e.g., ['faithfulness', 'relevance'])
     */
    public function execute(
        string $teamId,
        array $criteria,
        string $input,
        string $actualOutput,
        ?string $expectedOutput = null,
        ?string $context = null,
        ?string $experimentId = null,
        ?string $agentId = null,
        ?string $judgeModel = null,
    ): EvaluationRun {
        // Validate criteria
        $validCriteria = array_keys(config('evaluation.criteria', []));
        $criteria = array_intersect($criteria, $validCriteria);

        if (empty($criteria)) {
            throw new \InvalidArgumentException('No valid evaluation criteria specified. Available: '.implode(', ', $validCriteria));
        }

        $run = EvaluationRun::create([
            'team_id' => $teamId,
            'experiment_id' => $experimentId,
            'agent_id' => $agentId,
            'status' => EvaluationStatus::Running,
            'criteria' => $criteria,
            'started_at' => now(),
        ]);

        $totalCost = 0;
        $scores = [];

        foreach ($criteria as $criterion) {
            try {
                $result = $this->judge->evaluate(
                    criterion: $criterion,
                    input: $input,
                    actualOutput: $actualOutput,
                    expectedOutput: $expectedOutput,
                    context: $context,
                    model: $judgeModel,
                    teamId: $teamId,
                );

                $score = EvaluationScore::create([
                    'run_id' => $run->id,
                    'criterion' => $criterion,
                    'score' => $result['score'],
                    'reasoning' => $result['reasoning'],
                    'judge_model' => $judgeModel ?? config('evaluation.default_judge_model'),
                    'created_at' => now(),
                ]);

                $scores[$criterion] = $result['score'];
                $totalCost += $result['cost_credits'] ?? 0;
            } catch (\Throwable $e) {
                Log::warning('Evaluation criterion failed', [
                    'run_id' => $run->id,
                    'criterion' => $criterion,
                    'error' => $e->getMessage(),
                ]);

                $scores[$criterion] = null;
            }
        }

        $run->update([
            'status' => EvaluationStatus::Completed,
            'aggregate_scores' => $scores,
            'total_cost_credits' => $totalCost,
            'completed_at' => now(),
        ]);

        return $run;
    }
}
