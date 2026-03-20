<?php

namespace App\Infrastructure\AI\Translators;

use App\Infrastructure\AI\DTOs\AiResponseDTO;
use Illuminate\Support\Str;

class OpenAiResponseTranslator
{
    /**
     * Convert AiResponseDTO to OpenAI chat.completion format.
     */
    public function toOpenAiResponse(AiResponseDTO $response, string $requestModel): array
    {
        $id = 'chatcmpl-'.Str::ulid();

        return [
            'id' => $id,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $requestModel,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $response->content,
                    ],
                    'finish_reason' => $this->resolveFinishReason($response),
                ],
            ],
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'total_tokens' => $response->usage->totalTokens(),
            ],
            'system_fingerprint' => 'fleetq-v1',
        ];
    }

    /**
     * Format the initial SSE chunk (role announcement).
     */
    public function formatStreamStart(string $id, string $model): string
    {
        $chunk = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => ['role' => 'assistant', 'content' => ''],
                    'finish_reason' => null,
                ],
            ],
        ];

        return 'data: '.json_encode($chunk)."\n\n";
    }

    /**
     * Format a content delta SSE chunk.
     */
    public function formatStreamDelta(string $id, string $model, string $content): string
    {
        $chunk = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => ['content' => $content],
                    'finish_reason' => null,
                ],
            ],
        ];

        return 'data: '.json_encode($chunk)."\n\n";
    }

    /**
     * Format the final SSE chunk with finish reason.
     */
    public function formatStreamEnd(string $id, string $model, string $finishReason = 'stop'): string
    {
        $chunk = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => (object) [],
                    'finish_reason' => $finishReason,
                ],
            ],
        ];

        return 'data: '.json_encode($chunk)."\n\n";
    }

    /**
     * Format the usage SSE chunk (when stream_options.include_usage is true).
     */
    public function formatStreamUsage(string $id, string $model, AiResponseDTO $response): string
    {
        $chunk = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [],
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'total_tokens' => $response->usage->totalTokens(),
            ],
        ];

        return 'data: '.json_encode($chunk)."\n\n";
    }

    /**
     * The SSE stream termination signal.
     */
    public function formatStreamDone(): string
    {
        return "data: [DONE]\n\n";
    }

    /**
     * Format an error in OpenAI error format.
     */
    public static function formatError(string $message, string $type, int $httpStatus, ?string $code = null): array
    {
        return [
            'error' => [
                'message' => $message,
                'type' => $type,
                'param' => null,
                'code' => $code,
            ],
        ];
    }

    private function resolveFinishReason(AiResponseDTO $response): string
    {
        if ($response->toolCallsCount > 0) {
            return 'tool_calls';
        }

        return 'stop';
    }
}
