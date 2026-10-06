<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use App\Mcp\Concerns\ResolvesTeamChatbot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class ChatbotAnswerCachePurgeTool extends Tool
{
    use HasStructuredErrors, ResolvesTeamChatbot;

    protected string $name = 'chatbot_answer_cache_purge';

    protected string $description = 'Delete every cached answer of a chatbot so all next questions go through the full pipeline. Use after a wrong cached answer was reported.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('Chatbot UUID or slug')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['chatbot_id' => 'required|string']);

        $chatbot = $this->resolveTeamChatbot($validated['chatbot_id']);
        if ($chatbot instanceof Response) {
            return $chatbot;
        }

        app(ChatbotAnswerCache::class)->invalidate($chatbot->id);

        return Response::text(json_encode([
            'chatbot_id' => $chatbot->id,
            'purged' => true,
        ]));
    }
}
