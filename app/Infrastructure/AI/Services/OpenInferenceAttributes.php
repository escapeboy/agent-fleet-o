<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;

/**
 * Builds attribute lists shaped to OpenInference / OpenTelemetry semantic conventions
 * for LLM workloads (https://github.com/Arize-ai/openinference).
 *
 * The output of `forLlmCall()` is consumed by `ExportToPhoenixJob` to construct an
 * OTLP `Span.attributes` payload. Keeping this in one place means the attribute
 * naming stays consistent across writers and is easy to audit against the OI spec.
 *
 * No external dependency — attribute names are stable strings; OTLP wire format
 * is built by `toOtlpAttributes()` directly without protobuf tooling.
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

    /**
     * Build the flat attribute map for a single LLM gateway call.
     * Keys are OpenInference-canonical; values are scalar types (string|int|float|bool).
     *
     * Caller is responsible for adding the `error.*` family on failure.
     *
     * @return array<string, scalar|null>
     */
    public function forLlmCall(AiRequestDTO $request, AiResponseDTO $response): array
    {
        $promptTokens = $response->usage->promptTokens;
        $completionTokens = $response->usage->completionTokens;

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
            'llm.input_messages.0.message.content' => $request->systemPrompt,
            'llm.input_messages.1.message.role' => 'user',
            'llm.input_messages.1.message.content' => $request->userPrompt,
            'llm.output_messages.0.message.role' => 'assistant',
            'llm.output_messages.0.message.content' => $response->content,
            'metadata.purpose' => $request->purpose,
            'metadata.experiment_id' => $request->experimentId,
            'metadata.agent_id' => $request->agentId,
            'metadata.team_id' => $request->teamId,
            'metadata.user_id' => $request->userId,
            'metadata.cached' => $response->cached ? 'true' : null,
            'metadata.schema_valid' => $response->schemaValid ? 'true' : 'false',
            'metadata.tool_calls_count' => $response->toolCallsCount ?: null,
            'metadata.latency_ms' => $response->latencyMs,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Convert a flat attribute map to the OTLP "attributes" wire format:
     * `[{key, value: {stringValue|intValue|boolValue|doubleValue}}, ...]`.
     *
     * @param  array<string, scalar|null>  $attributes
     * @return list<array<string, mixed>>
     */
    public function toOtlpAttributes(array $attributes): array
    {
        $out = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $valueWrap = match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                default => ['stringValue' => (string) $value],
            };

            $out[] = ['key' => $key, 'value' => $valueWrap];
        }

        return $out;
    }
}
