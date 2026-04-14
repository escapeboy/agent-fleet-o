<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentConfigRevision;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('write')]
class AgentConfigHistoryTool extends Tool
{
    protected string $name = 'agent_config_history';

    protected string $description = 'List the configuration revision history for an agent. Each revision records what changed, before and after values, and who made the change.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Max revisions to return (default 20, max 50)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $revisions = AgentConfigRevision::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 20)
            ->get();

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'total' => AgentConfigRevision::withoutGlobalScopes()->where('agent_id', $agent->id)->count(),
            'revisions' => $revisions->map(fn (AgentConfigRevision $r) => [
                'id' => $r->id,
                'source' => $r->source,
                'changed_keys' => $r->changed_keys,
                'before_config' => $r->before_config,
                'after_config' => $r->after_config,
                'rolled_back_from_revision_id' => $r->rolled_back_from_revision_id,
                'notes' => $r->notes,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
        ]));
    }
}
