<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class ChatbotAnswerCachePurgeTool extends Tool
{
    use HasStructuredErrors;

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

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $idOrSlug = $validated['chatbot_id'];
        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(Str::isUuid($idOrSlug) ? 'id' : 'slug', $idOrSlug)
            ->first();

        if (! $chatbot) {
            return $this->notFoundError('chatbot', $idOrSlug);
        }

        app(ChatbotAnswerCache::class)->invalidate($chatbot->id);

        return Response::text(json_encode([
            'chatbot_id' => $chatbot->id,
            'purged' => true,
        ]));
    }
}
