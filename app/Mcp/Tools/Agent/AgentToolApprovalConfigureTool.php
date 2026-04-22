<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Models\Tool;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentToolApprovalConfigureTool extends McpTool
{
    use HasStructuredErrors;

    protected string $name = 'agent_tool_approval_configure';

    protected string $description = 'Configure tool-level execution approval settings on the agent-tool pivot. Controls whether a tool auto-executes, requires approval, or is denied.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'tool_id' => $schema->string()
                ->description('The tool UUID')
                ->required(),
            'approval_mode' => $schema->string()
                ->description('Approval mode: auto (default), ask (require approval), deny (always deny)')
                ->enum(['auto', 'ask', 'deny']),
            'approval_timeout_minutes' => $schema->integer()
                ->description('Minutes to wait for approval before timeout action (default 30)'),
            'approval_timeout_action' => $schema->string()
                ->description('Action on timeout: deny (fail), skip (continue without tool), allow (proceed)')
                ->enum(['deny', 'skip', 'allow']),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'tool_id' => 'required|string',
            'approval_mode' => 'nullable|string|in:auto,ask,deny',
            'approval_timeout_minutes' => 'nullable|integer|min:1|max:1440',
            'approval_timeout_action' => 'nullable|string|in:deny,skip,allow',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);
        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $tool = Tool::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['tool_id']);
        if (! $tool) {
            $tool = Tool::withoutGlobalScopes()->whereNull('team_id')->find($validated['tool_id']);
            if (! $tool) {
                return $this->notFoundError('tool');
            }
        }

        $pivotExists = DB::table('agent_tool')
            ->where('agent_id', $agent->id)
            ->where('tool_id', $tool->id)
            ->exists();

        if (! $pivotExists) {
            return $this->failedPreconditionError('Tool is not attached to this agent.');
        }

        $update = [];
        if (isset($validated['approval_mode'])) {
            $update['approval_mode'] = $validated['approval_mode'];
        }
        if (isset($validated['approval_timeout_minutes'])) {
            $update['approval_timeout_minutes'] = $validated['approval_timeout_minutes'];
        }
        if (isset($validated['approval_timeout_action'])) {
            $update['approval_timeout_action'] = $validated['approval_timeout_action'];
        }

        if (empty($update)) {
            return $this->invalidArgumentError('No fields to update. Provide at least one of: approval_mode, approval_timeout_minutes, approval_timeout_action.');
        }

        DB::table('agent_tool')
            ->where('agent_id', $agent->id)
            ->where('tool_id', $tool->id)
            ->update($update);

        $pivot = DB::table('agent_tool')
            ->where('agent_id', $agent->id)
            ->where('tool_id', $tool->id)
            ->first();

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'tool_id' => $tool->id,
            'approval_mode' => $pivot->approval_mode,
            'approval_timeout_minutes' => $pivot->approval_timeout_minutes,
            'approval_timeout_action' => $pivot->approval_timeout_action,
        ]));
    }
}
