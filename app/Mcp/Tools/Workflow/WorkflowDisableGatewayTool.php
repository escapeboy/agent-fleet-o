<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowDisableGatewayTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_disable_gateway';

    protected string $description = 'Remove a workflow from the MCP gateway. The tool will no longer be listed or callable after the next MCP server start.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID to remove from the gateway')
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

        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['workflow_id']);

        if (! $workflow) {
            return $this->notFoundError('workflow');
        }

        if (! $workflow->mcp_exposed) {
            return Response::text(json_encode([
                'success' => true,
                'message' => 'Workflow was not exposed as a gateway tool.',
            ]));
        }

        $previousName = $workflow->mcp_tool_name;

        $workflow->update([
            'mcp_exposed' => false,
            'mcp_tool_name' => null,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'workflow_id' => $workflow->id,
            'removed_tool_name' => $previousName,
            'message' => "MCP gateway disabled for workflow '{$workflow->name}'.",
        ]));
    }
}
