<?php

namespace App\Domain\Evaluation\Jobs;

use App\Domain\Evaluation\Actions\ScoreEvaluationResultAction;
use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationRunResult;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteFlowEvaluationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly string $runId,
    ) {
        $this->onQueue('experiments');
    }

    public function handle(ScoreEvaluationResultAction $scorer, AiGatewayInterface $gateway): void
    {
        $run = EvaluationRun::with(['dataset.rows', 'workflow'])->find($this->runId);

        if (! $run) {
            return;
        }

        $run->update([
            'status' => EvaluationStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $this->processRows($run, $scorer, $gateway);
        } catch (\Throwable $e) {
            Log::error('ExecuteFlowEvaluationJob failed', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => EvaluationStatus::Failed,
                'completed_at' => now(),
            ]);

            return;
        }

        $this->computeSummary($run);
    }

    private function processRows(EvaluationRun $run, ScoreEvaluationResultAction $scorer, AiGatewayInterface $gateway): void
    {
        $rows = $run->dataset->rows()->limit(50)->get();

        foreach ($rows as $row) {
            $startMs = now()->getPreciseTimestamp(3);

            $actualOutput = null;
            $error = null;

            try {
                $actualOutput = $this->runWorkflowPrompt($run, $row->input, $gateway);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                Log::warning('ExecuteFlowEvaluationJob row execution failed', [
                    'run_id' => $run->id,
                    'row_id' => $row->id,
                    'error' => $error,
                ]);
            }

            $executionTimeMs = (int) (now()->getPreciseTimestamp(3) - $startMs);

            $result = EvaluationRunResult::create([
                'run_id' => $run->id,
                'row_id' => $row->id,
                'actual_output' => $actualOutput,
                'execution_time_ms' => $executionTimeMs,
                'error' => $error,
                'created_at' => now(),
            ]);

            // Score if we have actual output and expected output
            if ($actualOutput !== null && $row->expected_output) {
                $scorer->execute(
                    result: $result,
                    expected: $row->expected_output,
                    actual: $actualOutput,
                    judgeModel: $run->judge_model ?? 'claude-haiku-4-5-20251001',
                    judgePrompt: $run->judge_prompt,
                    teamId: $run->team_id,
                );
            }
        }
    }

    /**
     * Run the workflow's prompt for a given row input using the AI gateway.
     * Uses the workflow description/name as context and row input as the user prompt.
     */
    private function runWorkflowPrompt(EvaluationRun $run, array $input, AiGatewayInterface $gateway): string
    {
        $workflow = $run->workflow;
        $userPrompt = is_string($input['prompt'] ?? null)
            ? $input['prompt']
            : json_encode($input);

        $systemPrompt = $workflow
            ? "You are executing workflow: {$workflow->name}. {$workflow->description}"
            : 'You are an AI assistant. Complete the task accurately.';

        $response = $gateway->complete(new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 2048,
            teamId: $run->team_id,
        ));

        return $response->content;
    }

    private function computeSummary(EvaluationRun $run): void
    {
        $results = EvaluationRunResult::where('run_id', $run->id)
            ->whereNotNull('score')
            ->get(['score', 'execution_time_ms']);

        $scores = $results->pluck('score')->filter()->values();
        $latencies = $results->pluck('execution_time_ms')->filter()->values()->sort()->values();

        $totalRows = EvaluationRunResult::where('run_id', $run->id)->count();
        $meanScore = $scores->isNotEmpty() ? round($scores->avg(), 3) : null;
        $passRate = $scores->isNotEmpty()
            ? round($scores->filter(fn ($s) => $s >= 0.7)->count() / $scores->count(), 3)
            : null;

        $p50 = $latencies->isNotEmpty() ? $latencies->get((int) floor($latencies->count() * 0.5)) : null;
        $p95 = $latencies->isNotEmpty() ? $latencies->get((int) floor($latencies->count() * 0.95)) : null;

        $run->update([
            'status' => EvaluationStatus::Completed,
            'completed_at' => now(),
            'summary' => [
                'total_rows' => $totalRows,
                'scored_rows' => $scores->count(),
                'mean_score' => $meanScore,
                'pass_rate' => $passRate,
                'p50_latency_ms' => $p50,
                'p95_latency_ms' => $p95,
            ],
        ]);
    }
}
