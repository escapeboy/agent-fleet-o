<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\CreateChatbotTokenAction;
use App\Domain\Chatbot\Models\Chatbot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
class ChatbotTokenCreateTool extends Tool
{
    protected string $name = 'chatbot_token_create';

    protected string $description = 'Generate an API token for a chatbot. The full token is returned ONLY once in this response — store it securely. Subsequent calls only return the prefix. Use rotate_existing=true to expire all current tokens with a 48-hour grace period.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()
                ->description('The chatbot UUID')
                ->required(),
            'name' => $schema->string()
                ->description('Label for this token, e.g. "Production" or "Website Integration"')
                ->default('Default'),
            'rotate_existing' => $schema->boolean()
                ->description('If true, existing active tokens get a 48-hour expiry grace period before being invalidated. Default: false')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'chatbot_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'rotate_existing' => 'nullable|boolean',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $chatbot = Chatbot::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['chatbot_id']);

        if (! $chatbot) {
            return Response::error('Chatbot not found.');
        }

        try {
            $result = app(CreateChatbotTokenAction::class)->execute(
                chatbot: $chatbot,
                name: $validated['name'] ?? 'Default',
                rotateExisting: $validated['rotate_existing'] ?? false,
            );

            return Response::text(json_encode([
                'success' => true,
                'chatbot_id' => $chatbot->id,
                'token_id' => $result['model']->id,
                'token_prefix' => $result['prefix'],
                'token' => $result['token'],
                'warning' => 'Store this token securely — it will not be shown again.',
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
