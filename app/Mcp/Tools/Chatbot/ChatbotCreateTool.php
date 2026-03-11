<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\CreateChatbotAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ChatbotCreateTool extends Tool
{
    protected string $name = 'chatbot_create';

    protected string $description = 'Create a new chatbot. Automatically creates a backing Agent with the given LLM settings. Returns the chatbot id and a one-time plaintext API token.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Chatbot display name')
                ->required(),
            'type' => $schema->string()
                ->description('Chatbot type')
                ->enum(['help_bot', 'support_assistant', 'developer_assistant', 'custom'])
                ->required(),
            'system_prompt' => $schema->string()
                ->description('System prompt for the backing agent')
                ->required(),
            'provider' => $schema->string()
                ->description('LLM provider (default: anthropic)')
                ->enum(['anthropic', 'openai', 'google'])
                ->default('anthropic'),
            'model' => $schema->string()
                ->description('LLM model (default: claude-haiku-4-5)'),
            'description' => $schema->string()
                ->description('Optional description'),
            'welcome_message' => $schema->string()
                ->description('Welcome message shown on first open'),
            'confidence_threshold' => $schema->number()
                ->description('Confidence threshold for escalation (0.0-1.0, default 0.7)'),
            'human_escalation_enabled' => $schema->boolean()
                ->description('Enable human escalation for low-confidence responses'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:help_bot,support_assistant,developer_assistant,custom',
            'system_prompt' => 'required|string',
            'provider' => 'nullable|string|in:anthropic,openai,google',
            'model' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'welcome_message' => 'nullable|string',
            'confidence_threshold' => 'nullable|numeric|between:0,1',
            'human_escalation_enabled' => 'nullable|boolean',
        ]);

        try {
            $result = app(CreateChatbotAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                name: $validated['name'],
                type: $validated['type'],
                systemPrompt: $validated['system_prompt'],
                provider: $validated['provider'] ?? 'anthropic',
                model: $validated['model'] ?? 'claude-haiku-4-5',
                description: $validated['description'] ?? null,
                welcomeMessage: $validated['welcome_message'] ?? null,
                confidenceThreshold: $validated['confidence_threshold'] ?? 0.7,
                humanEscalationEnabled: $validated['human_escalation_enabled'] ?? false,
            );

            return Response::text(json_encode([
                'success' => true,
                'chatbot_id' => $result['chatbot']->id,
                'slug' => $result['chatbot']->slug,
                'plaintext_token' => $result['plaintext_token'],
                'note' => 'Save the plaintext_token — it will not be shown again.',
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
