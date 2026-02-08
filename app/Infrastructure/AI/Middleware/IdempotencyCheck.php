<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Closure;

class IdempotencyCheck implements AiMiddlewareInterface
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
    ) {}

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        $idempotencyKey = $request->generateIdempotencyKey();

        // Check for existing completed request
        $existing = LlmRequestLog::where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->first();

        if ($existing) {
            $content = is_array($existing->response_body)
                ? json_encode($existing->response_body)
                : ($existing->response_body ?? '');

            return new AiResponseDTO(
                content: $content,
                parsedOutput: is_array($existing->response_body) ? $existing->response_body : null,
                usage: new AiUsageDTO(
                    promptTokens: $existing->input_tokens ?? 0,
                    completionTokens: $existing->output_tokens ?? 0,
                    costCredits: 0, // No cost for cached responses
                ),
                provider: $existing->provider,
                model: $existing->model,
                latencyMs: 0,
                cached: true,
            );
        }

        // Create pending log entry
        $log = LlmRequestLog::create([
            'idempotency_key' => $idempotencyKey,
            'agent_id' => $request->agentId,
            'experiment_id' => $request->experimentId,
            'experiment_stage_id' => $request->experimentStageId,
            'provider' => $request->provider,
            'model' => $request->model,
            'prompt_hash' => hash('xxh128', $request->systemPrompt . $request->userPrompt),
            'status' => 'pending',
        ]);

        try {
            $response = $next($request);

            $log->update([
                'status' => 'completed',
                'response_body' => $response->parsedOutput ?? ['text' => $response->content],
                'input_tokens' => $response->usage->promptTokens,
                'output_tokens' => $response->usage->completionTokens,
                'cost_credits' => $response->usage->costCredits,
                'latency_ms' => $response->latencyMs,
                'completed_at' => now(),
            ]);

            return $response;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
