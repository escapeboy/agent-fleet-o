<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WorkflowListGatewayToolsTool extends Tool
{
    protected string $name = 'workflow_list_gateway_tools';

    protected string $description = 'List all workflows currently exposed as MCP gateway tools across the server. Shows tool name, workflow name, execution mode, and owning team.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $exposed = Workflow::withoutGlobalScopes()
            ->where('mcp_exposed', true)
            ->whereNotNull('mcp_tool_name')
            ->orderBy('mcp_tool_name')
            ->get(['id', 'team_id', 'name', 'mcp_tool_name', 'mcp_execution_mode', 'description']);

        return Response::text(json_encode([
            'count' => $exposed->count(),
            'tools' => $exposed->map(fn ($w) => [
                'tool_name' => $w->mcp_tool_name,
                'workflow_name' => $w->name,
                'workflow_id' => $w->id,
                'team_id' => $w->team_id,
                'mcp_execution_mode' => $w->mcp_execution_mode,
                'description' => $w->description,
            ])->toArray(),
        ]));
    }
}
