<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Models\Chatbot;
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
class ChatbotListTool extends Tool
{
    protected string $name = 'chatbot_list';

    protected string $description = 'List chatbots for the current team. Returns id, name, slug, type, status, channel count.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: active, inactive, draft, suspended')
                ->enum(['active', 'inactive', 'draft', 'suspended']),
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

        $query = Chatbot::withCount(['channels', 'sessions'])
            ->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 20)), 100);
        $chatbots = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $chatbots->count(),
            'chatbots' => $chatbots->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'type' => $c->type->value,
                'status' => $c->status->value,
                'channels_count' => $c->channels_count,
                'sessions_count' => $c->sessions_count,
                'human_escalation_enabled' => $c->human_escalation_enabled,
                'confidence_threshold' => $c->confidence_threshold,
            ])->toArray(),
        ]));
    }
}
