<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AgentHook;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class AgentHookListTool extends Tool
{
    protected string $name = 'agent_hook_list';

    protected string $description = 'List lifecycle hooks for an agent. Returns both agent-specific and team-wide (class-level) hooks.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('Agent UUID to list hooks for')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = auth()->user()->current_team_id;

        $hooks = AgentHook::where('team_id', $teamId)
            ->where(function ($q) use ($request) {
                $q->where('agent_id', $request->string('agent_id'))
                    ->orWhereNull('agent_id');
            })
            ->orderBy('position')
            ->orderBy('priority')
            ->get()
            ->map(fn (AgentHook $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'position' => $h->position->value,
                'type' => $h->type->value,
                'config' => $h->config,
                'priority' => $h->priority,
                'enabled' => $h->enabled,
                'scope' => $h->isClassLevel() ? 'team' : 'agent',
            ]);

        return Response::text(json_encode($hooks, JSON_PRETTY_PRINT));
    }
}
