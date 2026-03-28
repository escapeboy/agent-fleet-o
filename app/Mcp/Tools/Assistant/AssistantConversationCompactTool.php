<?php

namespace App\Mcp\Tools\Assistant;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Services\ConversationCompactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class AssistantConversationCompactTool extends Tool
{
    protected string $name = 'assistant_conversation_compact';

    protected string $description = 'Manually trigger context compaction for a long assistant conversation. Creates a pinned summary snapshot and archives older messages. Useful before starting a new topic in a long-running conversation.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()
                ->description('Conversation UUID to compact')
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

        try {
            $snapshot = app(ConversationCompactor::class)->compact($conversation);

            return Response::text(json_encode([
                'success' => true,
                'snapshot_id' => $snapshot->id,
                'covered_count' => $snapshot->metadata['covered_count'] ?? 0,
                'compacted_at' => $snapshot->metadata['compacted_at'] ?? null,
            ]));
        } catch (\Throwable $e) {
            return Response::error('Compaction failed: '.$e->getMessage());
        }
    }
}
