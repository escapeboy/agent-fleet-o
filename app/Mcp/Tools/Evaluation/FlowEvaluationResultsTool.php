<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationRunResult;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class FlowEvaluationResultsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'flow_evaluation_results';

    protected string $description = 'Read results of a workflow evaluation run. Returns the summary (mean score, pass rate, latency) and per-row results.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()
                ->description('UUID of the evaluation run')
                ->required(),
            'include_rows' => $schema->boolean()
                ->description('Include per-row result details (default: false)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $run = EvaluationRun::with(['dataset', 'workflow'])->find($request->get('run_id'));

        if (! $run || $run->team_id !== $teamId) {
            return $this->notFoundError('evaluation run');
        }

        $payload = [
            'run_id' => $run->id,
            'status' => $run->status->value,
            'workflow_id' => $run->workflow_id,
            'dataset_id' => $run->dataset_id,
            'judge_model' => $run->judge_model,
            'summary' => $run->summary,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];

        if ($request->get('include_rows', false)) {
            $payload['results'] = EvaluationRunResult::where('run_id', $run->id)
                ->with('row')
                ->get()
                ->map(fn ($r) => [
                    'row_id' => $r->row_id,
                    'actual_output' => $r->actual_output,
                    'score' => $r->score,
                    'judge_reasoning' => $r->judge_reasoning,
                    'execution_time_ms' => $r->execution_time_ms,
                    'error' => $r->error,
                    'input' => $r->row?->input,
                    'expected_output' => $r->row?->expected_output,
                ])
                ->toArray();
        }

        return Response::text(json_encode($payload));
    }
}
