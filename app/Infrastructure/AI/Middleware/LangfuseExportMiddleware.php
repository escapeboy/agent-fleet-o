<?php

namespace App\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Jobs\ExportToLangfuseJob;
use App\Models\GlobalSetting;
use Closure;
use Illuminate\Support\Str;

/**
 * Optional last-in-pipeline middleware that exports every LLM call to Langfuse.
 * Only active when LANGFUSE_PUBLIC_KEY is set in the environment.
 * Fire-and-forget: dispatches a queued job, never blocks or fails the request.
 */
class LangfuseExportMiddleware implements AiMiddlewareInterface
{
    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        $response = $next($request);

        // Resolve effective config: GlobalSetting overrides env/config
        /** @var array<string, mixed> $overrides */
        $overrides = (array) (GlobalSetting::get('langfuse_config') ?? []);
        $enabled = isset($overrides['enabled']) ? (bool) $overrides['enabled'] : config('llmops.langfuse.enabled', false);
        $host = (string) ($overrides['host'] ?? config('llmops.langfuse.host', 'https://cloud.langfuse.com'));
        $publicKey = (string) ($overrides['public_key'] ?? config('llmops.langfuse.public_key', ''));
        $secretKey = (string) ($overrides['secret_key'] ?? config('llmops.langfuse.secret_key', ''));
        $maskContent = isset($overrides['mask_content']) ? (bool) $overrides['mask_content'] : config('llmops.langfuse.mask_content', false);

        if (! $enabled || empty($publicKey) || empty($secretKey)) {
            return $response;
        }

        if ($response->cached) {
            return $response;
        }

        $traceId = (string) Str::uuid();
        $generationId = (string) Str::uuid();
        $startTime = now()->subMilliseconds($response->latencyMs)->toIso8601String();
        $endTime = now()->toIso8601String();

        $metadata = array_filter([
            'purpose' => $request->purpose,
            'experiment_id' => $request->experimentId,
            'agent_id' => $request->agentId,
            'team_id' => $request->teamId,
            'schema_valid' => $response->schemaValid,
            'tool_calls_count' => $response->toolCallsCount ?: null,
        ]);

        ExportToLangfuseJob::dispatch(
            payload: [
                'id' => $generationId,
                'type' => 'generation-create',
                'timestamp' => $startTime,
                'body' => [
                    'id' => $generationId,
                    'traceId' => $traceId,
                    'name' => $request->purpose ?? 'llm-call',
                    'model' => $response->model,
                    'modelParameters' => [
                        'provider' => $response->provider,
                        'temperature' => $request->temperature,
                        'maxTokens' => $request->maxTokens,
                    ],
                    'input' => $maskContent
                        ? ['system' => '[REDACTED]', 'user' => '[REDACTED]']
                        : ['system' => $request->systemPrompt, 'user' => $request->userPrompt],
                    'output' => $response->parsedOutput ?? ['text' => $response->content],
                    'usage' => [
                        'input' => $response->usage->promptTokens,
                        'output' => $response->usage->completionTokens,
                        'total' => $response->usage->promptTokens + $response->usage->completionTokens,
                    ],
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                    'metadata' => $metadata ?: null,
                    'userId' => $request->userId,
                ],
            ],
            host: $host,
            publicKey: $publicKey,
            secretKey: $secretKey,
        );

        return $response;
    }
}
