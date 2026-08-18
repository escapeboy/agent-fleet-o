<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Enums\EvaluationCaseStatus;
use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Jobs\EvaluateDatasetCaseJob;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationRunResult;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Replay a curated EvaluationDataset against a new provider/model/prompt and
 * score each case with an LLM judge. Produces an EvaluationRun with per-case
 * `EvaluationRunResult` rows and aggregate scores on the run.
 *
 * Two execution models, both producing an identical `EvaluationRun` + summary:
 * - `execute()`     — sequential, in-process (used by the `sync` MCP option + tests).
 * - `dispatchParallel()` — fans one `EvaluateDatasetCaseJob` per case onto the
 *   `ai-calls` queue as a Bus batch, then recomputes the aggregate from the
 *   persisted rows in the batch `finally` callback. An eval run is almost all
 *   provider I/O wait, so fanning the cases out collapses wall-clock from
 *   `cases × (target + judges)` serialized calls to roughly one case-chain,
 *   bounded by the `ai-calls` worker pool (and the gateway's own rate limits).
 *
 * Used by `evaluation_replay_dataset` MCP tool + `ReplayEvaluationDatasetJob`.
 */
class ReplayEvaluationDatasetAction
{
    private const DEFAULT_CRITERIA = ['correctness', 'relevance'];

    /** Aggregate scores below this are flagged as regressions. */
    public const REGRESSION_THRESHOLD = 7.0;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly LlmJudge $judge,
    ) {}

    /**
     * Sequential replay: evaluate every case in-process, then finalize.
     *
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
        [$run, $cases] = $this->prepareRun($teamId, $datasetId, $criteria, $judgeModel, $maxCases, $targetProvider, $targetModel, $systemPrompt);

        foreach ($cases as $case) {
            $this->evaluateCase(
                run: $run,
                case: $case,
                targetProvider: $targetProvider,
                targetModel: $targetModel,
                systemPrompt: $systemPrompt,
                criteria: $run->criteria,
                judgeModel: $run->judge_model,
                teamId: $teamId,
            );
        }

        return $this->finalizeRun($run);
    }

    /**
     * Parallel replay: fan one job per case onto the `ai-calls` queue as a batch;
     * the batch `finally` callback recomputes the aggregate from persisted rows.
     * Returns the `Running` run immediately — poll it for completion.
     *
     * @param  list<string>  $criteria
     */
    public function dispatchParallel(
        string $teamId,
        string $datasetId,
        string $targetProvider,
        string $targetModel,
        ?string $systemPrompt = null,
        array $criteria = self::DEFAULT_CRITERIA,
        ?string $judgeModel = null,
        int $maxCases = 100,
    ): EvaluationRun {
        [$run, $cases] = $this->prepareRun($teamId, $datasetId, $criteria, $judgeModel, $maxCases, $targetProvider, $targetModel, $systemPrompt);

        $runCriteria = $run->criteria;
        $runJudgeModel = $run->judge_model;

        $jobs = $cases->map(fn (EvaluationCase $case) => new EvaluateDatasetCaseJob(
            teamId: $teamId,
            runId: (string) $run->id,
            caseId: (string) $case->id,
            targetProvider: $targetProvider,
            targetModel: $targetModel,
            systemPrompt: $systemPrompt,
            criteria: $runCriteria,
            judgeModel: $runJudgeModel,
        ))->values()->all();

        $runId = (string) $run->id;

        Bus::batch($jobs)
            ->name("eval-replay:{$runId}")
            ->allowFailures()
            ->onQueue('ai-calls')
            ->finally(function () use ($runId) {
                $run = EvaluationRun::withoutGlobalScopes()->find($runId);
                if ($run !== null && $run->status === EvaluationStatus::Running) {
                    app(self::class)->finalizeRun($run);
                }
            })
            ->dispatch();

        return $run;
    }

    /**
     * Validate the dataset + criteria and create the `Running` run.
     *
     * @param  list<string>  $criteria
     * @return array{0: EvaluationRun, 1: Collection<int, EvaluationCase>}
     */
    private function prepareRun(
        string $teamId,
        string $datasetId,
        array $criteria,
        ?string $judgeModel,
        int $maxCases,
        string $targetProvider,
        string $targetModel,
        ?string $systemPrompt,
    ): array {
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
            // Seed the target metadata so finalizeRun() (which runs without these
            // params, e.g. in the batch `finally` callback) can carry it through.
            'summary' => [
                'target_provider' => $targetProvider,
                'target_model' => $targetModel,
                'had_system_prompt_override' => $systemPrompt !== null && $systemPrompt !== '',
            ],
        ]);

        return [$run, $cases];
    }

    /**
     * Run a single case through the target model + judge and persist its
     * `EvaluationRunResult` row. Idempotent per (run, case): a prior row for the
     * same pair is replaced, so a retried batch job never double-counts.
     *
     * @param  list<string>  $criteria
     */
    public function evaluateCase(
        EvaluationRun $run,
        EvaluationCase $case,
        string $targetProvider,
        string $targetModel,
        ?string $systemPrompt,
        array $criteria,
        string $judgeModel,
        string $teamId,
    ): void {
        EvaluationRunResult::where('run_id', $run->id)->where('case_id', $case->id)->delete();

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
                'criterion_scores' => [],
                'cost_credits' => 0,
                'judge_reasoning' => null,
                'execution_time_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error' => 'target model failed: '.mb_strimwidth($e->getMessage(), 0, 500, '…'),
                'created_at' => now(),
            ]);

            return;
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
            'criterion_scores' => $criterionScores,
            'cost_credits' => $costCredits,
            'judge_reasoning' => json_encode($judgeReasonings),
            'execution_time_ms' => $durationMs,
            'error' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Recompute the run's aggregate scores + summary from its persisted
     * `EvaluationRunResult` rows and mark it Completed. Order-independent, so it
     * produces the same result whether the rows were written sequentially or by a
     * fan-out batch in arbitrary completion order.
     */
    public function finalizeRun(EvaluationRun $run): EvaluationRun
    {
        $criteria = array_values((array) $run->criteria);
        $results = EvaluationRunResult::where('run_id', $run->id)->get();

        /** @var Collection<string, EvaluationCase> $cases */
        $cases = EvaluationCase::withoutGlobalScopes()
            ->whereIn('id', $results->pluck('case_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $totalCases = $results->count();
        $passedCases = 0;
        $failedCases = 0;
        $erroredCases = 0;
        $deferredCount = 0;
        $deferredPassed = 0;
        /** @var list<array{case_id: string, score: float}> $silentWins */
        $silentWins = [];
        $criterionSums = array_fill_keys($criteria, 0.0);
        $criterionCounts = array_fill_keys($criteria, 0);
        $totalCostCredits = 0;

        foreach ($results as $result) {
            /** @var EvaluationRunResult $result */
            if ($result->error !== null) {
                $erroredCases++;

                continue;
            }

            $score = (float) $result->score;
            $isDeferred = ($cases[$result->case_id] ?? null)?->status === EvaluationCaseStatus::Deferred;

            // Deferred cases are scored but do NOT gate the run. A deferred case
            // scoring at/above threshold is a "silent win" — an unrelated change
            // accidentally fixed the failure mode (the deferred-eval pattern).
            if ($isDeferred) {
                $deferredCount++;
                if ($score >= self::REGRESSION_THRESHOLD) {
                    $deferredPassed++;
                    $silentWins[] = ['case_id' => (string) $result->case_id, 'score' => $score];
                }
            } elseif ($score >= self::REGRESSION_THRESHOLD) {
                $passedCases++;
            } else {
                $failedCases++;
            }

            foreach ((array) $result->criterion_scores as $criterion => $val) {
                if (array_key_exists($criterion, $criterionSums)) {
                    $criterionSums[$criterion] += (float) $val;
                    $criterionCounts[$criterion]++;
                }
            }
            $totalCostCredits += (int) $result->cost_credits;
        }

        $aggregate = [];
        foreach ($criteria as $criterion) {
            $count = $criterionCounts[$criterion] ?? 0;
            $aggregate[$criterion] = $count > 0
                ? round($criterionSums[$criterion] / $count, 2)
                : null;
        }

        // Pass rate is computed over gating (active, non-errored) cases only;
        // deferred cases are tracked separately and do not dilute the gate.
        $gatingCases = $passedCases + $failedCases;
        $passRate = $gatingCases > 0 ? round(($passedCases / $gatingCases) * 100, 1) : 0.0;
        $overallAvg = array_sum($aggregate) / max(1, count(array_filter($aggregate, fn ($v) => $v !== null)));

        $seeded = (array) $run->summary;

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
                'deferred_count' => $deferredCount,
                'deferred_passed' => $deferredPassed,
                'silent_wins' => $silentWins,
                'pass_rate_pct' => $passRate,
                'overall_avg_score' => round($overallAvg, 2),
                'regression_threshold' => self::REGRESSION_THRESHOLD,
                'target_provider' => $seeded['target_provider'] ?? null,
                'target_model' => $seeded['target_model'] ?? null,
                'had_system_prompt_override' => $seeded['had_system_prompt_override'] ?? false,
            ],
        ]);

        return $run->refresh();
    }
}
