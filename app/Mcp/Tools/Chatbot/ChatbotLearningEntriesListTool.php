<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotLearningEntry;
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
class ChatbotLearningEntriesListTool extends Tool
{
    protected string $name = 'chatbot_learning_entries_list';

    protected string $description = 'List operator corrections (learning entries) for a chatbot. Each entry represents a case where the operator modified the AI draft before approving.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('Chatbot UUID or slug')
                ->required(),
            'status' => $schema->string()
                ->description('Filter by status: pending_review, accepted, rejected, exported')
                ->enum(['pending_review', 'accepted', 'rejected', 'exported']),
            'limit' => $schema->integer()
                ->description('Max results (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! (auth()->user()->currentTeam?->settings['chatbot_enabled'] ?? false)) {
            return Response::error('Chatbot feature is not enabled for this team.');
        }

        $idOrSlug = $request->get('chatbot_id');
        $chatbot = Chatbot::where('id', $idOrSlug)->orWhere('slug', $idOrSlug)->first();

        if (! $chatbot) {
            return Response::error("Chatbot not found: {$idOrSlug}");
        }

        $limit = min((int) ($request->get('limit', 20)), 100);

        $query = ChatbotLearningEntry::where('chatbot_id', $chatbot->id)
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $entries = $query->get([
            'id', 'session_id', 'message_id', 'user_message',
            'original_response', 'corrected_response', 'reason_code',
            'status', 'created_at',
        ]);

        return Response::text(json_encode([
            'chatbot_id' => $chatbot->id,
            'total' => $entries->count(),
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'session_id' => $e->session_id,
                'message_id' => $e->message_id,
                'status' => $e->status->value,
                'user_message' => $e->user_message,
                'original_response' => $e->original_response,
                'corrected_response' => $e->corrected_response,
                'reason_code' => $e->reason_code,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values(),
        ]));
    }
}
