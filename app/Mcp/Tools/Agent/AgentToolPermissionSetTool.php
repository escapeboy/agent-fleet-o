<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class AgentToolPermissionSetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_tool_permission_set';

    protected string $description = 'Set the permission level for a specific tool assigned to an agent. Use read_only to restrict write/destructive operations.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'tool_id' => $schema->string()
                ->description('The tool UUID')
                ->required(),
            'permission_level' => $schema->string()
                ->description('Permission level: read_only, write, or destructive')
                ->enum(['read_only', 'write', 'destructive'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'tool_id' => 'required|string',
            'permission_level' => 'required|string|in:read_only,write,destructive',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);

        if (! $agent) {
            return $this->notFound('Agent', $validated['agent_id']);
        }

        $toolBelongsToTeam = DB::table('tools')
            ->where('id', $validated['tool_id'])
            ->where(function ($q) use ($teamId) {
                $q->where('team_id', $teamId)->orWhereNull('team_id');
            })
            ->exists();

        if (! $toolBelongsToTeam) {
            return $this->notFound('Tool', $validated['tool_id']);
        }

        $updated = DB::table('agent_tool')
            ->where('agent_id', $validated['agent_id'])
            ->where('tool_id', $validated['tool_id'])
            ->update(['permission_level' => $validated['permission_level']]);

        if (! $updated) {
            return $this->error('Tool not assigned to this agent', 404);
        }

        return Response::text(json_encode([
            'agent_id' => $validated['agent_id'],
            'tool_id' => $validated['tool_id'],
            'permission_level' => $validated['permission_level'],
            'message' => 'Tool permission level updated.',
        ], JSON_PRETTY_PRINT));
    }
}
