<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\CrewChatMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
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
class CrewBlackboardGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_blackboard_get';

    protected string $description = 'Get the full communication view for a crew execution: ephemeral blackboard posts (Redis sorted set, 24h TTL) plus the chat-room transcript (CrewChatMessage). Use crew_blackboard_read for just the blackboard posts.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew execution UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'execution_id' => 'required|string',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $execution = CrewExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['execution_id']);

        if (! $execution) {
            return $this->notFoundError('crew execution');
        }

        $raw = Redis::zrange('crew:blackboard:'.$execution->id, 0, -1);
        $blackboardPosts = collect($raw)
            ->map(fn ($entry) => json_decode((string) $entry, true))
            ->filter(fn ($decoded) => is_array($decoded))
            ->values()
            ->all();

        // CrewChatMessage has no team_id of its own; it is scoped transitively
        // through the already-team-scoped CrewExecution resolved above.
        $chatMessages = $execution->chatMessages()->get()
            ->map(fn (CrewChatMessage $m): array => [
                'agent_name' => $m->agent_name,
                'role' => $m->role,
                'content' => $m->content,
                'round' => $m->round,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->toArray();

        return Response::text(json_encode([
            'execution_id' => $execution->id,
            'blackboard_post_count' => count($blackboardPosts),
            'blackboard_posts' => $blackboardPosts,
            'chat_message_count' => count($chatMessages),
            'chat_messages' => $chatMessages,
        ]));
    }
}
