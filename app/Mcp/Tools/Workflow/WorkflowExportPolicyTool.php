<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\ExportWorkflowPolicyAction;
use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WorkflowExportPolicyTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_export_policy';

    protected string $description = 'Export a workflow\'s governance policy as a structured JSON document. Returns policy including approval gates, budget limits, and tool restrictions.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['workflow_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $workflow = Workflow::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['workflow_id']);

        if (! $workflow) {
            return $this->notFoundError('workflow');
        }

        $policy = app(ExportWorkflowPolicyAction::class)->execute($workflow);

        return Response::text($policy);
    }
}
