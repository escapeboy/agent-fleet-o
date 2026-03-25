<?php

namespace App\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Jobs\ExportToLangfuseJob;
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

        ExportToLangfuseJob::dispatch([
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
                'input' => [
                    'system' => $request->systemPrompt,
                    'user' => $request->userPrompt,
                ],
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
        ]);

        return $response;
    }
}
