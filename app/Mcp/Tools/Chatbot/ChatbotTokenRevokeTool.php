<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\RevokeChatbotTokenAction;
use App\Domain\Chatbot\Models\ChatbotToken;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ChatbotTokenRevokeTool extends Tool
{
    protected string $name = 'chatbot_token_revoke';

    protected string $description = 'Immediately revoke a chatbot API token. The token will be invalidated and any active integrations using it will stop working.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'token_id' => $schema->string()
                ->description('The chatbot token UUID (returned by chatbot_token_create)')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'token_id' => 'required|string',
        ]);

        $token = ChatbotToken::find($validated['token_id']);

        if (! $token) {
            return Response::error('Chatbot token not found.');
        }

        if ($token->revoked_at !== null) {
            return Response::text(json_encode([
                'success' => true,
                'token_id' => $validated['token_id'],
                'message' => 'Token was already revoked.',
                'revoked_at' => $token->revoked_at->toIso8601String(),
            ]));
        }

        try {
            app(RevokeChatbotTokenAction::class)->execute($token);

            return Response::text(json_encode([
                'success' => true,
                'token_id' => $validated['token_id'],
                'revoked' => true,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
