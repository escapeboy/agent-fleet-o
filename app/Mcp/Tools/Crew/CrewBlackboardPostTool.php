<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\CrewExecution;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Redis;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CrewBlackboardPostTool extends Tool
{
    protected string $name = 'crew_blackboard_post';

    protected string $description = 'Post a message to the crew execution blackboard — a shared ephemeral board visible to all agents in the crew. Use to broadcast STATUS updates, QUESTIONs to coordinators/other agents, or FINDING discoveries.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew execution UUID')
                ->required(),
            'type' => $schema->string()
                ->description('Message type: STATUS, QUESTION, or FINDING')
                ->required(),
            'message' => $schema->string()
                ->description('The message text')
                ->required(),
            'agent_name' => $schema->string()
                ->description('Name of the posting agent (optional)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'execution_id' => 'required|string',
            'type' => 'required|string|in:STATUS,QUESTION,FINDING',
            'message' => 'required|string',
            'agent_name' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $execution = CrewExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['execution_id']);

        if (! $execution) {
            return Response::error('Crew execution not found.');
        }

        $key = 'crew:blackboard:'.$validated['execution_id'];

        $payload = json_encode([
            'type' => $validated['type'],
            'agent_name' => $validated['agent_name'] ?? null,
            'message' => $validated['message'],
            'ts' => now()->toIso8601String(),
        ]);

        Redis::zadd($key, [microtime(true) => $payload]);
        Redis::expire($key, 86400);

        $count = (int) Redis::zcard($key);

        return Response::text(json_encode([
            'success' => true,
            'execution_id' => $validated['execution_id'],
            'message_count' => $count,
        ]));
    }
}
