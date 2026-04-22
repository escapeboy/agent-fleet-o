<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\UpdateChatbotAction;
use App\Domain\Chatbot\Models\Chatbot;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ChatbotUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'chatbot_update';

    protected string $description = 'Update chatbot name, description, config, widget_config, escalation settings, workflow, or LLM parameters.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Chatbot UUID or slug')
                ->required(),
            'name' => $schema->string()
                ->description('New display name'),
            'description' => $schema->string()
                ->description('New description'),
            'welcome_message' => $schema->string()
                ->description('Welcome message'),
            'fallback_message' => $schema->string()
                ->description('Fallback message for escalated responses'),
            'confidence_threshold' => $schema->number()
                ->description('Confidence threshold (0.0-1.0)'),
            'human_escalation_enabled' => $schema->boolean()
                ->description('Enable/disable human escalation'),
            'system_prompt' => $schema->string()
                ->description('New system prompt (updates backing agent backstory)'),
            'provider' => $schema->string()
                ->description('LLM provider key (e.g. anthropic, openai, google). Only applies to dedicated-agent chatbots.'),
            'model' => $schema->string()
                ->description('Model identifier matching the provider (e.g. claude-sonnet-4-5). Only applies to dedicated-agent chatbots.'),
            'widget_config' => $schema->object()
                ->description('Widget config: {position, theme_color, title}'),
            'workflow_id' => $schema->string()
                ->description('Workflow UUID to delegate message processing (null to use direct agent)'),
            'approval_timeout_hours' => $schema->integer()
                ->description('Hours before escalated approval request expires (default 48)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! (auth()->user()->currentTeam?->settings['chatbot_enabled'] ?? false)) {
            return $this->failedPreconditionError('Chatbot feature is not enabled for this team.');
        }

        $idOrSlug = $request->get('id');
        $chatbot = Chatbot::where('id', $idOrSlug)->orWhere('slug', $idOrSlug)->first();

        if (! $chatbot) {
            return $this->notFoundError('chatbot', $idOrSlug);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'welcome_message' => 'nullable|string',
            'fallback_message' => 'nullable|string',
            'confidence_threshold' => 'nullable|numeric|between:0,1',
            'human_escalation_enabled' => 'nullable|boolean',
            'system_prompt' => 'nullable|string',
            'provider' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:100',
            'widget_config' => 'nullable|array',
            'workflow_id' => 'nullable|uuid',
            'approval_timeout_hours' => 'nullable|integer|min:1|max:720',
        ]);

        try {
            $updated = app(UpdateChatbotAction::class)->execute(
                chatbot: $chatbot,
                name: $validated['name'] ?? null,
                description: $validated['description'] ?? null,
                welcomeMessage: $validated['welcome_message'] ?? null,
                fallbackMessage: $validated['fallback_message'] ?? null,
                confidenceThreshold: $validated['confidence_threshold'] ?? null,
                humanEscalationEnabled: $validated['human_escalation_enabled'] ?? null,
                widgetConfig: $validated['widget_config'] ?? null,
                workflowId: $validated['workflow_id'] ?? null,
                approvalTimeoutHours: $validated['approval_timeout_hours'] ?? null,
                provider: $validated['provider'] ?? null,
                model: $validated['model'] ?? null,
                systemPrompt: $validated['system_prompt'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'chatbot_id' => $updated->id,
                'name' => $updated->name,
                'status' => $updated->status->value,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
