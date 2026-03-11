<?php

namespace App\Mcp\Tools\Chatbot;

use App\Domain\Chatbot\Models\Chatbot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ChatbotGetTool extends Tool
{
    protected string $name = 'chatbot_get';

    protected string $description = 'Get full details of a chatbot by ID or slug, including config, widget_config, and active tokens.';

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
        $idOrSlug = $request->get('id');

        $chatbot = Chatbot::with(['agent', 'activeTokens', 'channels'])
            ->where('id', $idOrSlug)
            ->orWhere('slug', $idOrSlug)
            ->first();

        if (! $chatbot) {
            return Response::error("Chatbot not found: {$idOrSlug}");
        }

        return Response::text(json_encode([
            'id' => $chatbot->id,
            'name' => $chatbot->name,
            'slug' => $chatbot->slug,
            'description' => $chatbot->description,
            'type' => $chatbot->type->value,
            'status' => $chatbot->status->value,
            'agent_id' => $chatbot->agent_id,
            'agent_is_dedicated' => $chatbot->agent_is_dedicated,
            'config' => $chatbot->config,
            'widget_config' => $chatbot->widget_config,
            'confidence_threshold' => $chatbot->confidence_threshold,
            'human_escalation_enabled' => $chatbot->human_escalation_enabled,
            'welcome_message' => $chatbot->welcome_message,
            'fallback_message' => $chatbot->fallback_message,
            'active_tokens' => $chatbot->activeTokens->map(fn ($t) => [
                'prefix' => $t->token_prefix,
                'name' => $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
            ])->toArray(),
            'channels' => $chatbot->channels->map(fn ($ch) => [
                'type' => $ch->channel_type,
                'is_active' => $ch->is_active,
            ])->toArray(),
            'created_at' => $chatbot->created_at->toIso8601String(),
            'updated_at' => $chatbot->updated_at->toIso8601String(),
        ]));
    }
}
