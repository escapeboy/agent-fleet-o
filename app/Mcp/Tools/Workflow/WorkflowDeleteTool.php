<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class WorkflowDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_delete';

    protected string $description = 'Delete a workflow. Only draft workflows can be deleted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()->description('The workflow ID to delete.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $workflow = Workflow::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('workflow_id'));
        if (! $workflow) {
            return $this->notFoundError('workflow');
        }

        if ($workflow->status !== WorkflowStatus::Draft) {
            return $this->failedPreconditionError('Only draft workflows can be deleted. Current status: '.$workflow->status->value);
        }

        $workflow->delete();

        return Response::text(json_encode([
            'success' => true,
            'id' => $request->get('workflow_id'),
        ]));
    }
}
