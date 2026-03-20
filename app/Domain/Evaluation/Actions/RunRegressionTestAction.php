<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationScore;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Domain\Shared\Exceptions\PlanLimitExceededException;
use App\Domain\Shared\Models\Team;
use Illuminate\Support\Facades\Log;

class RunRegressionTestAction
{
    public function __construct(
        private readonly LlmJudge $judge,
    ) {}

    /**
     * Run a dataset of evaluation cases against the judge.
     *
     * @param  array<string>  $criteria
     * @param  callable(string): string  $executor  Function that takes input and returns output
     */
    public function execute(
        string $teamId,
        string $datasetId,
        array $criteria,
        callable $executor,
        ?string $agentId = null,
        ?string $judgeModel = null,
    ): EvaluationRun {
        $dataset = EvaluationDataset::findOrFail($datasetId);

        // Case count limit per plan tier
        $team = Team::findOrFail($teamId);
        $maxCases = $this->getMaxCases($team);
        if ($dataset->case_count > $maxCases) {
            throw new PlanLimitExceededException(
                "Dataset has {$dataset->case_count} cases, plan allows {$maxCases}.",
            );
        }

        $run = EvaluationRun::create([
            'team_id' => $teamId,
            'dataset_id' => $datasetId,
            'agent_id' => $agentId,
            'status' => EvaluationStatus::Running,
            'criteria' => $criteria,
            'started_at' => now(),
        ]);

        $totalCost = 0;
        $criteriaScores = array_fill_keys($criteria, []);

        $dataset->cases()->chunk(50, function ($cases) use (
            $run, $criteria, $executor, $judgeModel, $teamId, &$totalCost, &$criteriaScores,
        ) {
            foreach ($cases as $case) {
                try {
                    $actualOutput = $executor($case->input);
                } catch (\Throwable $e) {
                    Log::warning('Regression test executor failed', [
                        'run_id' => $run->id,
                        'case_id' => $case->id,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                foreach ($criteria as $criterion) {
                    try {
                        $result = $this->judge->evaluate(
                            criterion: $criterion,
                            input: $case->input,
                            actualOutput: $actualOutput,
                            expectedOutput: $case->expected_output,
                            context: $case->context,
                            model: $judgeModel,
                            teamId: $teamId,
                        );

                        EvaluationScore::create([
                            'run_id' => $run->id,
                            'case_id' => $case->id,
                            'criterion' => $criterion,
                            'score' => $result['score'],
                            'reasoning' => $result['reasoning'],
                            'judge_model' => $judgeModel ?? config('evaluation.default_judge_model'),
                            'created_at' => now(),
                        ]);

                        $criteriaScores[$criterion][] = $result['score'];
                        $totalCost += $result['cost_credits'] ?? 0;
                    } catch (\Throwable $e) {
                        Log::warning('Regression test criterion failed', [
                            'run_id' => $run->id,
                            'case_id' => $case->id,
                            'criterion' => $criterion,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });

        // Calculate aggregate scores
        $aggregateScores = [];
        foreach ($criteriaScores as $criterion => $scores) {
            $aggregateScores[$criterion] = empty($scores) ? null : round(array_sum($scores) / count($scores), 2);
        }

        $run->update([
            'status' => EvaluationStatus::Completed,
            'aggregate_scores' => $aggregateScores,
            'total_cost_credits' => $totalCost,
            'completed_at' => now(),
        ]);

        return $run;
    }

    private function getMaxCases(Team $team): int
    {
        $plan = $team->plan ?? 'free';

        return match ($plan) {
            'free' => 10,
            'starter' => 50,
            'pro' => 200,
            'enterprise' => $team->custom_limits['max_evaluation_cases'] ?? 1000,
            default => 10,
        };
    }
}
