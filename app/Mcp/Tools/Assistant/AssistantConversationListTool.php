<?php

namespace App\Mcp\Tools\Assistant;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class AssistantConversationListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'assistant_conversation_list';

    protected string $description = 'List assistant conversations for the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max results (default 20, max 50)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $limit = min((int) ($request->get('limit') ?? 20), 50);

        $conversations = AssistantConversation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('last_message_at')
            ->limit($limit)
            ->get()
            ->map(fn (AssistantConversation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'context_type' => $c->context_type,
                'context_id' => $c->context_id,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String(),
            ]);

        return Response::text(json_encode([
            'conversations' => $conversations,
            'total' => $conversations->count(),
        ]));
    }
}
