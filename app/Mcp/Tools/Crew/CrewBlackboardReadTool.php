<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\CrewExecution;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Redis;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class CrewBlackboardReadTool extends Tool
{
    protected string $name = 'crew_blackboard_read';

    protected string $description = 'Read messages from the crew execution blackboard. Returns ephemeral messages posted by agents — STATUS updates, QUESTIONs, and FINDINGs. Messages expire 24h after the last post.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew execution UUID')
                ->required(),
            'type' => $schema->string()
                ->description('Filter by type: STATUS, QUESTION, or FINDING (optional)'),
            'limit' => $schema->number()
                ->description('Max messages to return (default 50, max 200)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'execution_id' => 'required|string',
            'type' => 'nullable|string|in:STATUS,QUESTION,FINDING',
            'limit' => 'nullable|integer|min:1|max:200',
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
        $raw = Redis::zrange($key, 0, -1);

        $messages = collect($raw)
            ->map(fn ($item) => json_decode($item, true))
            ->filter()
            ->when(
                isset($validated['type']),
                fn ($col) => $col->filter(fn ($m) => ($m['type'] ?? null) === $validated['type']),
            )
            ->take($validated['limit'] ?? 50)
            ->values()
            ->all();

        return Response::text(json_encode([
            'execution_id' => $validated['execution_id'],
            'message_count' => count($messages),
            'messages' => $messages,
        ]));
    }
}
