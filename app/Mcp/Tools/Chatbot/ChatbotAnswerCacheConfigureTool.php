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
#[AssistantTool('write')]
class ChatbotAnswerCacheConfigureTool extends Tool
{
    use HasStructuredErrors, ResolvesTeamChatbot;

    protected string $name = 'chatbot_answer_cache_configure';

    protected string $description = 'Turn the semantic answer cache of a chatbot on or off and set its TTL and size cap. Turning it off also deletes the cached answers. The cache only runs when the platform switch CHATBOT_ANSWER_CACHE_ENABLED is also on.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('Chatbot UUID or slug')
                ->required(),
            'enabled' => $schema->boolean()
                ->description('Enable or disable the answer cache for this chatbot'),
            'ttl_hours' => $schema->integer()
                ->description('Hours a cached answer stays valid (1-2160, default 168)'),
            'max_entries' => $schema->integer()
                ->description('Maximum cached answers kept, least recently used dropped first (10-5000, default 500)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chatbot_id' => 'required|string',
            'enabled' => 'sometimes|boolean',
            'ttl_hours' => 'sometimes|integer|min:1|max:2160',
            'max_entries' => 'sometimes|integer|min:10|max:5000',
        ]);

        $chatbot = $this->resolveTeamChatbot($validated['chatbot_id']);
        if ($chatbot instanceof Response) {
            return $chatbot;
        }

        $settings = app(ChatbotAnswerCache::class)->updateSettings(
            $chatbot,
            array_intersect_key($validated, array_flip(['enabled', 'ttl_hours', 'max_entries'])),
        );

        return Response::text(json_encode([
            'chatbot_id' => $chatbot->id,
            'answer_cache' => $settings,
            'platform_enabled' => (bool) config('chatbot_answer_cache.enabled', false),
        ]));
    }
}
