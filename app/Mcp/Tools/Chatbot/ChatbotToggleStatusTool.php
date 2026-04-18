<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\ToggleChatbotStatusAction;
use App\Domain\Chatbot\Models\Chatbot;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ChatbotToggleStatusTool extends Tool
{
    protected string $name = 'chatbot_toggle_status';

    protected string $description = 'Activate or deactivate a chatbot. Inactive chatbots return the fallback message to visitors.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Chatbot UUID or slug')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! (auth()->user()->currentTeam?->settings['chatbot_enabled'] ?? false)) {
            return Response::error('Chatbot feature is not enabled for this team.');
        }

        $idOrSlug = $request->get('id');
        $chatbot = Chatbot::where('id', $idOrSlug)->orWhere('slug', $idOrSlug)->first();

        if (! $chatbot) {
            return Response::error("Chatbot not found: {$idOrSlug}");
        }

        try {
            $updated = app(ToggleChatbotStatusAction::class)->execute($chatbot);

            return Response::text(json_encode([
                'success' => true,
                'chatbot_id' => $updated->id,
                'new_status' => $updated->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
