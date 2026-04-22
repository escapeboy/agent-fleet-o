<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\DeleteChatbotAction;
use App\Domain\Chatbot\Models\Chatbot;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class ChatbotDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'chatbot_delete';

    protected string $description = 'Delete a chatbot. Revokes all active API tokens, deactivates all channels, and soft-deletes the backing agent if it was auto-created.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('The chatbot UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chatbot_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $chatbot = Chatbot::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['chatbot_id']);

        if (! $chatbot) {
            return $this->notFoundError('chatbot');
        }

        try {
            app(DeleteChatbotAction::class)->execute($chatbot);

            return Response::text(json_encode([
                'success' => true,
                'chatbot_id' => $validated['chatbot_id'],
                'deleted' => true,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
