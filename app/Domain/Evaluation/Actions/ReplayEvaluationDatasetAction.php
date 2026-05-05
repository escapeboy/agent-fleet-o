<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationRunResult;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

/**
 * Replay a curated EvaluationDataset against a new provider/model/prompt and
 * score each case with an LLM judge. Produces an EvaluationRun with per-case
 * `EvaluationRunResult` rows and aggregate scores on the run.
 *
 * Used by `evaluation_replay_dataset` MCP tool + `ReplayEvaluationDatasetJob`
 * for async long-running replays.
 */
final class ReplayEvaluationDatasetAction
{
    private const DEFAULT_CRITERIA = ['correctness', 'relevance'];

    /** Aggregate scores below this are flagged as regressions. */
    public const REGRESSION_THRESHOLD = 7.0;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly LlmJudge $judge,
    ) {}

    /**
     * @param  list<string>  $criteria  Judge criteria (faithfulness, relevance, correctness, completeness)
     * @return EvaluationRun with completed status + aggregate_scores + summary
     */
    public function execute(
        string $teamId,
        string $datasetId,
        string $targetProvider,
        string $targetModel,
        ?string $systemPrompt = null,
        array $criteria = self::DEFAULT_CRITERIA,
        ?string $judgeModel = null,
        int $maxCases = 100,
    ): EvaluationRun {
        $dataset = EvaluationDataset::query()->where('team_id', $teamId)->find($datasetId);
        if ($dataset === null) {
            throw new \RuntimeException("Dataset {$datasetId} not found for this team");
        }

        $validCriteria = array_keys(config('evaluation.criteria', []));
        $criteria = array_values(array_intersect($criteria, $validCriteria));
        if ($criteria === []) {
            throw new \InvalidArgumentException('No valid evaluation criteria. Available: '.implode(', ', $validCriteria));
        }

        $cases = $dataset->cases()->orderBy('created_at')->limit(max(1, min(500, $maxCases)))->get();
        if ($cases->isEmpty()) {
            throw new \RuntimeException("Dataset {$datasetId} has no cases to replay");
        }

        $run = EvaluationRun::create([
            'team_id' => $teamId,
            'dataset_id' => $dataset->id,
            'status' => EvaluationStatus::Running,
            'criteria' => $criteria,
            'judge_model' => $judgeModel ?? config('evaluation.default_judge_model'),
            'started_at' => now(),
        ]);

        $totalCases = 0;
        $passedCases = 0;
        $failedCases = 0;
        $erroredCases = 0;
        $criterionSums = array_fill_keys($criteria, 0.0);
        $criterionCounts = array_fill_keys($criteria, 0);
        $totalCostCredits = 0;

        foreach ($cases as $case) {
            $totalCases++;
            $caseOutcome = $this->runSingleCase(
                run: $run,
                case: $case,
                targetProvider: $targetProvider,
                targetModel: $targetModel,
                systemPrompt: $systemPrompt,
                criteria: $criteria,
                judgeModel: $run->judge_model,
                teamId: $teamId,
            );

            if ($caseOutcome['error'] !== null) {
                $erroredCases++;

                continue;
            }

            $score = $caseOutcome['avg_score'];
            if ($score >= self::REGRESSION_THRESHOLD) {
                $passedCases++;
            } else {
                $failedCases++;
            }

            foreach ($caseOutcome['criterion_scores'] as $criterion => $val) {
                $criterionSums[$criterion] += $val;
                $criterionCounts[$criterion]++;
            }
            $totalCostCredits += $caseOutcome['cost_credits'];
        }

        $aggregate = [];
        foreach ($criteria as $criterion) {
            $count = $criterionCounts[$criterion] ?? 0;
            $aggregate[$criterion] = $count > 0
                ? round($criterionSums[$criterion] / $count, 2)
                : null;
        }

        $passRate = $totalCases > 0 ? round(($passedCases / $totalCases) * 100, 1) : 0.0;
        $overallAvg = array_sum($aggregate) / max(1, count(array_filter($aggregate, fn ($v) => $v !== null)));

        $run->update([
            'status' => EvaluationStatus::Completed,
            'aggregate_scores' => $aggregate,
            'total_cost_credits' => $totalCostCredits,
            'completed_at' => now(),
            'summary' => [
                'total_cases' => $totalCases,
                'passed' => $passedCases,
                'failed' => $failedCases,
                'errored' => $erroredCases,
                'pass_rate_pct' => $passRate,
                'overall_avg_score' => round($overallAvg, 2),
                'regression_threshold' => self::REGRESSION_THRESHOLD,
                'target_provider' => $targetProvider,
                'target_model' => $targetModel,
                'had_system_prompt_override' => $systemPrompt !== null && $systemPrompt !== '',
            ],
        ]);

        return $run->refresh();
    }

    /**
     * @param  list<string>  $criteria
     * @return array{error: ?string, avg_score: float, criterion_scores: array<string,float>, cost_credits: int}
     */
    private function runSingleCase(
        EvaluationRun $run,
        EvaluationCase $case,
        string $targetProvider,
        string $targetModel,
        ?string $systemPrompt,
        array $criteria,
        string $judgeModel,
        string $teamId,
    ): array {
        $started = hrtime(true);
        $systemPromptText = $systemPrompt ?? 'You are a helpful AI assistant. Answer the question directly and concisely.';

        // Step 1: run the user input through the target provider/model.
        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $targetProvider,
                model: $targetModel,
                systemPrompt: $systemPromptText,
                userPrompt: (string) $case->input,
                maxTokens: 1024,
                teamId: $teamId,
                purpose: 'evaluation_replay',
                temperature: 0.2,
            ));
            $actualOutput = trim((string) $response->content);
        } catch (\Throwable $e) {
            EvaluationRunResult::create([
                'run_id' => $run->id,
                'case_id' => $case->id,
                'actual_output' => null,
                'score' => 0,
                'judge_reasoning' => null,
                'execution_time_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error' => 'target model failed: '.mb_strimwidth($e->getMessage(), 0, 500, '…'),
                'created_at' => now(),
            ]);

            return ['error' => $e->getMessage(), 'avg_score' => 0, 'criterion_scores' => [], 'cost_credits' => 0];
        }

        // Step 2: ask the judge how it compares to expected_output for each criterion.
        $criterionScores = [];
        $judgeReasonings = [];
        $costCredits = (int) ($response->usage->costCredits ?? 0);

        foreach ($criteria as $criterion) {
            try {
                $result = $this->judge->evaluate(
                    criterion: $criterion,
                    input: (string) $case->input,
                    actualOutput: $actualOutput,
                    expectedOutput: (string) $case->expected_output,
                    context: (string) $case->context,
                    model: $judgeModel,
                    teamId: $teamId,
                );
                $criterionScores[$criterion] = (float) ($result['score'] ?? 0);
                $judgeReasonings[$criterion] = (string) ($result['reasoning'] ?? '');
                $costCredits += (int) ($result['cost_credits'] ?? 0);
            } catch (\Throwable $e) {
                Log::warning('ReplayEvaluationDatasetAction: judge failed', [
                    'run_id' => $run->id,
                    'case_id' => $case->id,
                    'criterion' => $criterion,
                    'error' => $e->getMessage(),
                ]);
                $criterionScores[$criterion] = 0;
                $judgeReasonings[$criterion] = 'judge_error: '.mb_strimwidth($e->getMessage(), 0, 200, '…');
            }
        }

        $avgScore = count($criterionScores) > 0
            ? round(array_sum($criterionScores) / count($criterionScores), 2)
            : 0;
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        EvaluationRunResult::create([
            'run_id' => $run->id,
            'case_id' => $case->id,
            'actual_output' => mb_strimwidth($actualOutput, 0, 4096, '…'),
            'score' => $avgScore,
            'judge_reasoning' => json_encode($judgeReasonings),
            'execution_time_ms' => $durationMs,
            'error' => null,
            'created_at' => now(),
        ]);

        return [
            'error' => null,
            'avg_score' => $avgScore,
            'criterion_scores' => $criterionScores,
            'cost_credits' => $costCredits,
        ];
    }
}
