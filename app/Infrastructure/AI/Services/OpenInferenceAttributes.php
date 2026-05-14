<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;

/**
 * Builds attribute lists shaped to OpenInference / OpenTelemetry semantic
 * conventions for LLM workloads (https://github.com/Arize-ai/openinference).
 *
 * `forLlmCall()` returns a flat scalar-valued attribute map; the consumer
 * (`ExportToPhoenixJob`) feeds it to the OTel PHP SDK's `$span->setAttribute()`,
 * which handles protobuf encoding.
 */
class OpenInferenceAttributes
{
    public const SPAN_KIND = 'openinference.span.kind';

    public const LLM_MODEL = 'llm.model_name';

    public const LLM_PROVIDER = 'llm.provider';

    public const LLM_TOKEN_COUNT_PROMPT = 'llm.token_count.prompt';

    public const LLM_TOKEN_COUNT_COMPLETION = 'llm.token_count.completion';

    public const LLM_TOKEN_COUNT_TOTAL = 'llm.token_count.total';

    public const LLM_INVOCATION_PARAMETERS = 'llm.invocation_parameters';

    public const MASKED = '[REDACTED]';

    /**
     * Build the flat attribute map for a single LLM gateway call.
     * Keys are OpenInference-canonical; values are scalar types (string|int|float|bool).
     *
     * When $maskContent is true, system/user/assistant message content is
     * replaced with the [REDACTED] sentinel. Token counts, model, provider
     * and metadata always stay — they're not PII.
     *
     * @return array<string, scalar|null>
     */
    public function forLlmCall(AiRequestDTO $request, AiResponseDTO $response, bool $maskContent = false): array
    {
        $promptTokens = $response->usage->promptTokens;
        $completionTokens = $response->usage->completionTokens;

        $systemContent = $maskContent ? self::MASKED : $request->systemPrompt;
        $userContent = $maskContent ? self::MASKED : $request->userPrompt;
        $assistantContent = $maskContent ? self::MASKED : $response->content;

        return array_filter([
            self::SPAN_KIND => 'LLM',
            self::LLM_MODEL => $response->model,
            self::LLM_PROVIDER => $response->provider,
            self::LLM_TOKEN_COUNT_PROMPT => $promptTokens,
            self::LLM_TOKEN_COUNT_COMPLETION => $completionTokens,
            self::LLM_TOKEN_COUNT_TOTAL => $promptTokens + $completionTokens,
            self::LLM_INVOCATION_PARAMETERS => json_encode([
                'temperature' => $request->temperature,
                'max_tokens' => $request->maxTokens,
            ]),
            'llm.input_messages.0.message.role' => 'system',
            'llm.input_messages.0.message.content' => $systemContent,
            'llm.input_messages.1.message.role' => 'user',
            'llm.input_messages.1.message.content' => $userContent,
            'llm.output_messages.0.message.role' => 'assistant',
            'llm.output_messages.0.message.content' => $assistantContent,
            'metadata.purpose' => $request->purpose,
            'metadata.experiment_id' => $request->experimentId,
            'metadata.agent_id' => $request->agentId,
            'metadata.team_id' => $request->teamId,
            'metadata.user_id' => $request->userId,
            'metadata.cached' => $response->cached ? 'true' : null,
            'metadata.schema_valid' => $response->schemaValid ? 'true' : 'false',
            'metadata.tool_calls_count' => $response->toolCallsCount ?: null,
            'metadata.latency_ms' => $response->latencyMs,
            'metadata.masked' => $maskContent ? 'true' : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
