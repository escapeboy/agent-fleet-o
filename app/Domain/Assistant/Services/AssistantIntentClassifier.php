<?php

namespace App\Domain\Assistant\Services;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Prism\Prism\Tool as PrismToolObject;
use Throwable;

/**
 * Lightweight intent classifier that determines whether a user message
 * requires a platform tool call or is purely conversational/informational.
 *
 * Uses the same provider/model as the main request so BYOK credentials
 * are applied automatically. The call is minimal: maxTokens=5, temperature=0.
 */
class AssistantIntentClassifier
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * @param  array<PrismToolObject>  $tools
     */
    public function requiresToolCall(
        string $message,
        array $tools,
        string $provider,
        string $model,
        string $userId,
        ?string $teamId,
    ): bool {
        if (empty($tools)) {
            return false;
        }

        $toolNames = implode(', ', array_map(fn (PrismToolObject $t) => $t->name(), $tools));

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: <<<PROMPT
                You are an intent classifier for a platform assistant.
                Determine if the user's message requires calling one of the available tools.

                Available tools: {$toolNames}

                Reply with ONLY the single word YES or NO.
                YES = the user wants to create, update, delete, list, or retrieve platform data.
                NO  = the user is asking a general question, chatting, or asking for advice only.
                PROMPT,
                userPrompt: $message,
                maxTokens: 5,
                userId: $userId,
                teamId: $teamId,
                purpose: 'assistant_intent_classification',
                temperature: 0.0,
            ));

            return str_contains(strtoupper(trim($response->content ?? '')), 'YES');
        } catch (Throwable) {
            // On error, default to requiring a tool call so the main request
            // uses toolChoice='any' — better to force tool use than silently skip it.
            return true;
        }
    }
}
