<?php

namespace App\Infrastructure\AI\Translators;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class OpenAiRequestTranslator
{
    /**
     * Resolve the model namespace and return the routing info.
     *
     * @return array{type: string, provider: string, model: string, entity: Agent|Crew|null}
     */
    public function resolveModel(string $modelId, string $teamId): array
    {
        // Explicit namespace: "agent/slug" or "crew/slug"
        if (str_starts_with($modelId, 'agent/')) {
            $slug = substr($modelId, 6);
            $agent = Agent::where('team_id', $teamId)->where('slug', $slug)->first();

            if (! $agent) {
                return ['type' => 'not_found', 'provider' => '', 'model' => '', 'entity' => null];
            }

            return ['type' => 'agent', 'provider' => $agent->provider, 'model' => $agent->model, 'entity' => $agent];
        }

        if (str_starts_with($modelId, 'crew/')) {
            $slug = substr($modelId, 5);
            $crew = Crew::where('team_id', $teamId)->where('slug', $slug)->first();

            if (! $crew) {
                return ['type' => 'not_found', 'provider' => '', 'model' => '', 'entity' => null];
            }

            return ['type' => 'crew', 'provider' => '', 'model' => '', 'entity' => $crew];
        }

        // Provider/model passthrough: "anthropic/claude-sonnet-4-5"
        if (str_contains($modelId, '/')) {
            [$provider, $model] = explode('/', $modelId, 2);

            return ['type' => 'passthrough', 'provider' => $provider, 'model' => $model, 'entity' => null];
        }

        // No namespace — try agent slug first, then treat as raw model
        $agent = Agent::where('team_id', $teamId)->where('slug', $modelId)->first();
        if ($agent) {
            return ['type' => 'agent', 'provider' => $agent->provider, 'model' => $agent->model, 'entity' => $agent];
        }

        // Fallback: assume anthropic provider
        return ['type' => 'passthrough', 'provider' => 'anthropic', 'model' => $modelId, 'entity' => null];
    }

    /**
     * Convert OpenAI-format request to AiRequestDTO.
     */
    public function toAiRequest(
        array $validated,
        string $provider,
        string $model,
        string $userId,
        string $teamId,
        ?string $agentId = null,
    ): AiRequestDTO {
        $messages = $validated['messages'] ?? [];

        // Extract system prompt from messages
        $systemPrompt = '';
        $conversationParts = [];

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'] ?? '';

            if ($role === 'system') {
                $systemPrompt .= ($systemPrompt !== '' ? "\n" : '').$content;
            } elseif ($role === 'tool') {
                $toolCallId = $message['tool_call_id'] ?? 'unknown';
                $conversationParts[] = "Tool result ({$toolCallId}): {$content}";
            } elseif ($role === 'assistant' && isset($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $tc) {
                    $fn = $tc['function'] ?? [];
                    $conversationParts[] = "Assistant called tool: {$fn['name']}({$fn['arguments']})";
                }
                if ($content !== '') {
                    $conversationParts[] = "Assistant: {$content}";
                }
            } else {
                $label = ucfirst($role);
                $conversationParts[] = "{$label}: {$content}";
            }
        }

        $userPrompt = implode("\n\n", $conversationParts);

        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt ?: 'You are a helpful assistant.',
            userPrompt: $userPrompt,
            maxTokens: $validated['max_tokens'] ?? 4096,
            userId: $userId,
            teamId: $teamId,
            agentId: $agentId,
            purpose: 'openai_compatible',
            temperature: (float) ($validated['temperature'] ?? 0.7),
        );
    }
}
