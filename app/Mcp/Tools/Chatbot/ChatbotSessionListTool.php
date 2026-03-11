<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotSession;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ChatbotSessionListTool extends Tool
{
    protected string $name = 'chatbot_session_list';

    protected string $description = 'List conversation sessions for a chatbot. Returns session id, channel, message count, last activity.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('Chatbot UUID or slug')
                ->required(),
            'channel' => $schema->string()
                ->description('Filter by channel: web_widget, api, telegram, slack'),
            'limit' => $schema->integer()
                ->description('Max results (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $idOrSlug = $request->get('chatbot_id');
        $chatbot = Chatbot::where('id', $idOrSlug)->orWhere('slug', $idOrSlug)->first();

        if (! $chatbot) {
            return Response::error("Chatbot not found: {$idOrSlug}");
        }

        $query = ChatbotSession::withCount('messages')
            ->where('chatbot_id', $chatbot->id)
            ->orderByDesc('last_activity_at');

        if ($channel = $request->get('channel')) {
            $query->where('channel', $channel);
        }

        $limit = min((int) ($request->get('limit', 20)), 100);
        $sessions = $query->limit($limit)->get();

        return Response::text(json_encode([
            'chatbot_id' => $chatbot->id,
            'count' => $sessions->count(),
            'sessions' => $sessions->map(fn ($s) => [
                'id' => $s->id,
                'channel' => $s->channel,
                'messages_count' => $s->messages_count,
                'started_at' => $s->started_at?->toIso8601String(),
                'last_activity_at' => $s->last_activity_at?->toIso8601String(),
                'ip_address' => $s->ip_address,
            ])->toArray(),
        ]));
    }
}
