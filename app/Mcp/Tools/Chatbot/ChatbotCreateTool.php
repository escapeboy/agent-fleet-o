<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\CreateChatbotAction;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
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
            'workflow_id' => $schema->string()
                ->description('Optional workflow UUID to delegate message processing'),
            'approval_timeout_hours' => $schema->integer()
                ->description('Hours before escalated approval expires (default 48)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! (auth()->user()->currentTeam?->settings['chatbot_enabled'] ?? false)) {
            return Response::error('Chatbot feature is not enabled for this team.');
        }

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
            'workflow_id' => 'nullable|uuid',
            'approval_timeout_hours' => 'nullable|integer|min:1|max:720',
        ]);
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        try {
            $result = app(CreateChatbotAction::class)->execute(
                teamId: $teamId,
                name: $validated['name'],
                type: ChatbotType::from($validated['type']),
                systemPrompt: $validated['system_prompt'],
                provider: $validated['provider'] ?? 'anthropic',
                model: $validated['model'] ?? 'claude-haiku-4-5',
                welcomeMessage: $validated['welcome_message'] ?? null,
                workflowId: $validated['workflow_id'] ?? null,
                approvalTimeoutHours: $validated['approval_timeout_hours'] ?? null,
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
