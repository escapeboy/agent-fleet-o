<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Actions\CreateFlowEvaluationDatasetAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class FlowEvaluationDatasetCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'flow_evaluation_dataset_create';

    protected string $description = 'Create a workflow evaluation dataset with test rows. Each row has an input (JSON) and optional expected_output for LLM judge scoring.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Dataset name')
                ->required(),
            'description' => $schema->string()
                ->description('Optional description'),
            'workflow_id' => $schema->string()
                ->description('UUID of the workflow this dataset targets'),
            'rows' => $schema->array(items: $schema->object(properties: [
                'input' => $schema->object()->description('Input data as a JSON object (e.g. {"prompt": "..."}')->required(),
                'expected_output' => $schema->string()->description('Expected output text for judge scoring'),
                'metadata' => $schema->object()->description('Optional metadata'),
            ]))
                ->description('Test rows for the dataset'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        try {
            $dataset = app(CreateFlowEvaluationDatasetAction::class)->execute(
                teamId: $teamId,
                name: $request->get('name'),
                description: $request->get('description'),
                workflowId: $request->get('workflow_id'),
                rows: $request->get('rows', []),
            );

            return Response::text(json_encode([
                'success' => true,
                'dataset_id' => $dataset->id,
                'name' => $dataset->name,
                'row_count' => $dataset->row_count,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
