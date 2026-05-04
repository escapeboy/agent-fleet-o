<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Set the per-agent tool deny list. Tool IDs in the list are filtered out
 * by ResolveAgentToolsAction before the LLM tool-loop, regardless of pivot
 * approval_mode or project-level allowlists. The deny list is the strongest
 * "no" — operator's escape hatch for restricting an agent's capability surface.
 */
#[IsDestructive]
#[AssistantTool('write')]
class AgentToolDenySetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_tool_deny_set';

    protected string $description = 'Replace an agent\'s tool deny list with the provided array of tool UUIDs. Empty array clears the list.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Agent UUID')->required(),
            'tool_ids' => $schema->string()->description('Comma-separated list of tool UUIDs to deny. Empty string clears the list.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $validated = $request->validate([
            'agent_id' => "required|string|uuid|exists:agents,id,team_id,{$teamId}",
            'tool_ids' => 'required|string',
        ]);

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);
        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $rawIds = trim($validated['tool_ids']);
        $ids = $rawIds === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $rawIds))));

        // Validate each ID is a UUID for a tool in this team.
        if ($ids !== []) {
            $validIds = \App\Domain\Tool\Models\Tool::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereIn('id', $ids)
                ->pluck('id')
                ->all();

            $invalid = array_diff($ids, $validIds);
            if ($invalid !== []) {
                return $this->invalidArgumentError(
                    'Some tool IDs do not belong to this team or do not exist: '.implode(', ', $invalid),
                );
            }
        }

        $agent->update(['tool_deny_list' => $ids === [] ? null : $ids]);

        return Response::json([
            'agent_id' => $agent->id,
            'tool_deny_list' => $agent->fresh()->tool_deny_list ?? [],
        ]);
    }
}
