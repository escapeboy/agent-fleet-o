<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Enums\SignalInferredIntent;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class ClassifySignalIntentAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function execute(Signal $signal): ?SignalInferredIntent
    {
        $text = $this->buildSignalText($signal);
        if (trim($text) === '') {
            return null;
        }

        $allowed = implode(', ', SignalInferredIntent::values());

        $system = <<<PROMPT
        You classify the *intent* of a single incoming customer/operational signal.
        Return ONE of: {$allowed}.

        Definitions:
        - action_completed: somebody shipped, signed, paid, replied with a completion (e.g. "contract signed", "intro made", "deploy successful").
        - blocker_raised: somebody escalated, objected, flagged a bug, reported something broken.
        - stale_deal: a follow-up that restates no progress, a nudge, a "still waiting" message.
        - information_request: questions, RFPs, asks for clarification.
        - positive_engagement: thanks, praise, general rapport-building without a specific action.
        - neutral: doesn't fit any of the above, or routine ops noise.

        Return JSON: {"intent": "<value>", "reasoning": "<short>"}.
        PROMPT;

        $schema = new ObjectSchema(
            name: 'classification',
            description: 'Signal intent classification',
            properties: [
                new StringSchema('intent', 'One of '.$allowed),
                new StringSchema('reasoning', 'Brief justification — single sentence'),
            ],
            requiredFields: ['intent', 'reasoning'],
        );

        $provider = GlobalSetting::get('default_llm_provider', 'anthropic');
        $model = GlobalSetting::get('default_llm_model', 'claude-haiku-4-5-20251001');

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $system,
                userPrompt: $text,
                maxTokens: 200,
                outputSchema: $schema,
                teamId: $signal->team_id,
                purpose: 'signal_intent_classification',
                temperature: 0.1,
            ));

            $parsed = $response->parsedOutput ?? (is_string($response->content) ? json_decode($response->content, true) : null);
            if (! is_array($parsed) || empty($parsed['intent'])) {
                return null;
            }

            $intent = SignalInferredIntent::tryFrom((string) $parsed['intent']);
            if ($intent === null) {
                return null;
            }

            $metadata = $signal->metadata ?? [];
            $metadata['inferred_intent'] = $intent->value;
            $metadata['inferred_intent_reasoning'] = mb_strimwidth((string) ($parsed['reasoning'] ?? ''), 0, 280, '…');
            $metadata['inferred_intent_classifier'] = sprintf('%s/%s', $provider, $model);
            $metadata['inferred_intent_at'] = now()->toIso8601String();
            $signal->metadata = $metadata;
            $signal->save();

            return $intent;
        } catch (\Throwable $e) {
            Log::warning('ClassifySignalIntentAction: LLM call failed', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildSignalText(Signal $signal): string
    {
        $source = $signal->source_type ?? 'unknown';
        $payload = $signal->payload ?? [];
        $normalised = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : (string) $payload;

        return sprintf("Source: %s\nPayload: %s", $source, mb_strimwidth($normalised, 0, 2000, '…'));
    }
}
