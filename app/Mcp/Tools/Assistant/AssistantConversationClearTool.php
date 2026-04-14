<?php

namespace App\Mcp\Tools\Assistant;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AssistantConversationClearTool extends Tool
{
    protected string $name = 'assistant_conversation_clear';

    protected string $description = 'Delete an assistant conversation and all its messages.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()
                ->description('Conversation UUID to delete')
                ->required(),
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

        $conversation->messages()->delete();
        $conversation->delete();

        return Response::text(json_encode(['success' => true, 'message' => 'Conversation deleted.']));
    }
}
