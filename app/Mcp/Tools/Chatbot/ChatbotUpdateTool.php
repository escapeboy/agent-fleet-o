<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Actions\UpdateChatbotAction;
use App\Domain\Chatbot\Models\Chatbot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ChatbotUpdateTool extends Tool
{
    protected string $name = 'chatbot_update';

    protected string $description = 'Update chatbot name, description, config, widget_config, escalation settings, or LLM parameters.';

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
                ->description('New system prompt (updates backing agent)'),
            'widget_config' => $schema->object()
                ->description('Widget config: {position, theme_color, title}'),
        ];
    }

    public function handle(Request $request): Response
    {
        $idOrSlug = $request->get('id');
        $chatbot = Chatbot::where('id', $idOrSlug)->orWhere('slug', $idOrSlug)->first();

        if (! $chatbot) {
            return Response::error("Chatbot not found: {$idOrSlug}");
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'welcome_message' => 'nullable|string',
            'fallback_message' => 'nullable|string',
            'confidence_threshold' => 'nullable|numeric|between:0,1',
            'human_escalation_enabled' => 'nullable|boolean',
            'system_prompt' => 'nullable|string',
            'widget_config' => 'nullable|array',
        ]);

        try {
            $updated = app(UpdateChatbotAction::class)->execute($chatbot, array_filter($validated, fn ($v) => $v !== null));

            return Response::text(json_encode([
                'success' => true,
                'chatbot_id' => $updated->id,
                'name' => $updated->name,
                'status' => $updated->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
