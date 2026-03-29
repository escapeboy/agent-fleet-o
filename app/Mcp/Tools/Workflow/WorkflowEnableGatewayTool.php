<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowEnableGatewayTool extends Tool
{
    protected string $name = 'workflow_enable_gateway';

    protected string $description = 'Expose a workflow as a named MCP tool via the gateway. The tool_name must be unique across all teams (snake_case, 3-64 chars). Use mcp_execution_mode=sync for workflows without human_task nodes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID to expose')
                ->required(),
            'tool_name' => $schema->string()
                ->description('Unique MCP tool name (snake_case, e.g. send_weekly_report). Must match /^[a-z][a-z0-9_]{2,63}$/.')
                ->required(),
            'mcp_execution_mode' => $schema->string()
                ->description('Execution mode: sync (runs inline, blocks until complete) or async (dispatches experiment, returns ID). Default: async.')
                ->enum(['sync', 'async']),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'tool_name' => 'required|string|regex:/^[a-z][a-z0-9_]{2,63}$/',
            'mcp_execution_mode' => 'nullable|string|in:sync,async',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        // Check tool_name uniqueness across all teams
        $toolName = $validated['tool_name'];
        $conflict = Workflow::withoutGlobalScopes()
            ->where('mcp_tool_name', $toolName)
            ->where('id', '!=', $workflow->id)
            ->exists();

        if ($conflict) {
            return Response::error("The tool name '{$toolName}' is already in use by another workflow. Choose a different name.");
        }

        $workflow->update([
            'mcp_exposed' => true,
            'mcp_tool_name' => $toolName,
            'mcp_execution_mode' => $validated['mcp_execution_mode'] ?? 'async',
        ]);

        return Response::text(json_encode([
            'success' => true,
            'workflow_id' => $workflow->id,
            'tool_name' => $toolName,
            'mcp_execution_mode' => $workflow->mcp_execution_mode,
            'message' => "Workflow exposed as MCP tool '{$toolName}'. The tool will be available on the next MCP server start.",
        ]));
    }
}
