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
#[AssistantTool('write')]
class WorkflowDeactivateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_deactivate';

    protected string $description = 'Deactivate a workflow, returning it to draft status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()->description('The workflow ID to deactivate.')->required(),
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

        $workflow->status = WorkflowStatus::Draft;
        $workflow->save();

        return Response::text(json_encode([
            'success' => true,
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status->value,
        ]));
    }
}
