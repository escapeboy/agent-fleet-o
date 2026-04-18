<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\CrewAgentMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class CrewGetMessagesTool extends Tool
{
    protected string $name = 'crew_get_messages';

    protected string $description = 'Get inter-agent messages for a crew execution. Filter by round or recipient.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_execution_id' => $schema->string()
                ->description('The crew execution UUID')
                ->required(),
            'round' => $schema->integer()
                ->description('Filter by debate/execution round (optional)'),
            'recipient_agent_id' => $schema->string()
                ->description('Filter messages addressed to this agent UUID (optional)'),
            'limit' => $schema->integer()
                ->description('Maximum number of messages to return (default: 50)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'crew_execution_id' => 'required|string',
            'round' => 'nullable|integer',
            'recipient_agent_id' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $execution = CrewExecution::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['crew_execution_id']);

        if (! $execution) {
            return Response::error('Crew execution not found.');
        }

        $query = CrewAgentMessage::where('crew_execution_id', $execution->id)
            ->with(['sender', 'recipient']);

        if (isset($validated['round'])) {
            $query->where('round', $validated['round']);
        }

        if (isset($validated['recipient_agent_id'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('recipient_agent_id', $validated['recipient_agent_id'])
                    ->orWhereNull('recipient_agent_id');
            });
        }

        $messages = $query
            ->orderBy('round')
            ->orderBy('created_at')
            ->limit($validated['limit'] ?? 50)
            ->get()
            ->map(fn (CrewAgentMessage $msg) => [
                'id' => $msg->id,
                'message_type' => $msg->message_type,
                'round' => $msg->round,
                'content' => $msg->content,
                'is_read' => $msg->is_read,
                'sender_name' => $msg->sender?->name,
                'recipient_name' => $msg->recipient?->name,
                'parent_message_id' => $msg->parent_message_id,
                'created_at' => $msg->created_at?->toIso8601String(),
            ])
            ->toArray();

        return Response::text(json_encode([
            'execution_id' => $execution->id,
            'count' => count($messages),
            'messages' => $messages,
        ]));
    }
}
