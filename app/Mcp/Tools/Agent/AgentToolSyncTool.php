<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Models\Tool as AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class AgentToolSyncTool extends Tool
{
    protected string $name = 'agent_tool_sync';

    protected string $description = 'Attach, detach, or replace tools on an agent. Mode "sync" replaces all tools, "attach" adds the given tools, "detach" removes them.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'tool_ids' => $schema->array()
                ->description('Array of tool UUIDs to attach/detach/sync')
                ->required(),
            'mode' => $schema->string()
                ->description('Operation mode: sync (replace all), attach (add), detach (remove). Default: sync')
                ->enum(['sync', 'attach', 'detach']),
        ];
    }

    public function handle(Request $request): Response
    {
        $agentId = $request->get('agent_id');
        $toolIds = $request->get('tool_ids', []);
        $mode = $request->get('mode', 'sync');

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($agentId);
        if (! $agent) {
            return Response::error("Agent {$agentId} not found.");
        }

        if (! is_array($toolIds)) {
            return Response::error('tool_ids must be an array of UUIDs.');
        }

        // Validate that all tool IDs exist
        $validTools = AgentTool::whereIn('id', $toolIds)->pluck('id')->toArray();
        $invalidIds = array_diff($toolIds, $validTools);
        if (! empty($invalidIds)) {
            return Response::error('Invalid tool IDs: '.implode(', ', $invalidIds).'. Use tool_list to discover valid tool IDs.');
        }

        try {
            match ($mode) {
                'sync' => $agent->tools()->sync($toolIds),
                'attach' => $agent->tools()->syncWithoutDetaching($toolIds),
                'detach' => $agent->tools()->detach($toolIds),
            };

            $agent->load('tools:id,name');

            return Response::text(json_encode([
                'success' => true,
                'agent_id' => $agent->id,
                'mode' => $mode,
                'attached_tool_count' => $agent->tools->count(),
                'attached_tool_ids' => $agent->tools->pluck('id')->toArray(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
