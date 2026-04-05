<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Actions\RunFlowEvaluationAction;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class FlowEvaluationRunStartTool extends Tool
{
    protected string $name = 'flow_evaluation_run_start';

    protected string $description = 'Start a workflow evaluation run against a dataset. Runs the workflow on each row and scores outputs with an LLM judge. Results are available via flow_evaluation_results.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset_id' => $schema->string()
                ->description('UUID of the evaluation dataset')
                ->required(),
            'workflow_id' => $schema->string()
                ->description('UUID of the workflow to evaluate')
                ->required(),
            'judge_model' => $schema->string()
                ->description('LLM model for scoring (default: claude-haiku-4-5-20251001). Format: "provider/model" or just "model-name"'),
            'judge_prompt' => $schema->string()
                ->description('Custom scoring prompt. Use {expected} and {actual} as placeholders.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $datasetId = $request->get('dataset_id');
        $dataset = EvaluationDataset::find($datasetId);

        if (! $dataset || $dataset->team_id !== $teamId) {
            return Response::error("Dataset not found: {$datasetId}");
        }

        $workflowId = $request->get('workflow_id');
        $workflow = Workflow::withoutGlobalScopes()
            ->where('id', $workflowId)
            ->where('team_id', $teamId)
            ->first();
        if (! $workflow) {
            return Response::error("Workflow not found: {$workflowId}");
        }

        try {
            $run = app(RunFlowEvaluationAction::class)->execute(
                dataset: $dataset,
                workflowId: $workflowId,
                judgeModel: $request->get('judge_model'),
                judgePrompt: $request->get('judge_prompt'),
            );

            return Response::text(json_encode([
                'success' => true,
                'run_id' => $run->id,
                'status' => $run->status->value,
                'dataset_id' => $run->dataset_id,
                'workflow_id' => $run->workflow_id,
                'judge_model' => $run->judge_model,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
