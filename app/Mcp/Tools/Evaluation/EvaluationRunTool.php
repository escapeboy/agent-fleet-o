<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Actions\RunStructuredEvaluationAction;
use App\Domain\Evaluation\Models\EvaluationRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class EvaluationRunTool extends Tool
{
    protected string $name = 'evaluation_run';

    protected string $description = 'Run a structured multi-criteria evaluation on a given input/output pair, or view existing runs.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: run, get, list')
                ->enum(['run', 'get', 'list'])
                ->required(),
            'run_id' => $schema->string()
                ->description('Run ID (for get action)'),
            'criteria' => $schema->array(items: $schema->string())
                ->description('Criteria to evaluate: faithfulness, relevance, correctness, completeness'),
            'input' => $schema->string()
                ->description('The input/task that was given'),
            'actual_output' => $schema->string()
                ->description('The actual output to evaluate'),
            'expected_output' => $schema->string()
                ->description('Expected output for comparison'),
            'context' => $schema->string()
                ->description('Ground truth context for faithfulness'),
            'experiment_id' => $schema->string()
                ->description('Link to an experiment'),
            'agent_id' => $schema->string()
                ->description('Link to an agent'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');

        return match ($action) {
            'run' => $this->runEvaluation($request),
            'get' => $this->getRun($request->get('run_id')),
            'list' => $this->listRuns(),
            default => Response::text(json_encode(['error' => "Unknown action: {$action}"])),
        };
    }

    private function runEvaluation(Request $request): Response
    {
        $teamId = auth()->user()?->currentTeam?->id ?? '';
        $criteria = $request->get('criteria', ['faithfulness', 'relevance']);

        $run = app(RunStructuredEvaluationAction::class)->execute(
            teamId: $teamId,
            criteria: $criteria,
            input: $request->get('input', ''),
            actualOutput: $request->get('actual_output', ''),
            expectedOutput: $request->get('expected_output'),
            context: $request->get('context'),
            experimentId: $request->get('experiment_id'),
            agentId: $request->get('agent_id'),
        );

        return Response::text(json_encode([
            'run_id' => $run->id,
            'status' => $run->status->value,
            'scores' => $run->aggregate_scores,
            'cost_credits' => $run->total_cost_credits,
        ]));
    }

    private function getRun(?string $id): Response
    {
        if (! $id) {
            return Response::text(json_encode(['error' => 'run_id required']));
        }

        $run = EvaluationRun::with('scores')->find($id);
        if (! $run) {
            return Response::text(json_encode(['error' => 'Run not found']));
        }

        return Response::text(json_encode([
            'id' => $run->id,
            'status' => $run->status->value,
            'criteria' => $run->criteria,
            'aggregate_scores' => $run->aggregate_scores,
            'total_cost_credits' => $run->total_cost_credits,
            'scores' => $run->scores->map(fn ($s) => [
                'criterion' => $s->criterion,
                'score' => $s->score,
                'reasoning' => $s->reasoning,
                'judge_model' => $s->judge_model,
            ])->toArray(),
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ]));
    }

    private function listRuns(): Response
    {
        $runs = EvaluationRun::query()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get(['id', 'status', 'criteria', 'aggregate_scores', 'total_cost_credits', 'created_at']);

        return Response::text(json_encode(['runs' => $runs->toArray()]));
    }
}
