<?php

namespace App\Mcp\Tools\Assistant;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
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
class AssistantConversationGetTool extends Tool
{
    protected string $name = 'assistant_conversation_get';

    protected string $description = 'Get messages in an assistant conversation.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()
                ->description('Conversation UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Max messages to return (default 50, max 200)')
                ->default(50),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return Response::error('No team context.');
        }

        $conversation = AssistantConversation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('conversation_id'))
            ->first();

        if (! $conversation) {
            return Response::error('Conversation not found.');
        }

        $limit = min((int) ($request->get('limit') ?? 50), 200);

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AssistantMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'created_at' => $m->created_at,
            ]);

        return Response::text(json_encode([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ],
            'messages' => $messages,
            'total' => $messages->count(),
        ]));
    }
}
