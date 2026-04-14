<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AiRun;
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
class AgentExecutionsListTool extends Tool
{
    protected string $name = 'agent_executions_list';

    protected string $description = 'List recent AI runs/executions for a specific agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The agent ID.')->required(),
            'limit' => $schema->integer()->description('Maximum number of results to return (default 20).')->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $limit = min((int) ($request->get('limit', 20)), 100);

        $runs = AiRun::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('agent_id', $request->get('agent_id'))
            ->latest()
            ->limit($limit)
            ->get(['id', 'agent_id', 'input_tokens', 'output_tokens', 'cost_credits', 'created_at']);

        return Response::text(json_encode([
            'count' => $runs->count(),
            'runs' => $runs->map(fn ($r) => [
                'id' => $r->id,
                'agent_id' => $r->agent_id,
                'input_tokens' => $r->input_tokens,
                'output_tokens' => $r->output_tokens,
                'cost_credits' => $r->cost_credits,
                'created_at' => $r->created_at,
            ])->toArray(),
        ]));
    }
}
