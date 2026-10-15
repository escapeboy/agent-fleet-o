<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Closure;
use Illuminate\Support\Str;

class IdempotencyCheck implements AiMiddlewareInterface
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
    ) {}

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        // A tool-using request must never replay a stored answer: the derived key
        // ignores tools, and a replay returns the old final text with zero tool
        // calls, so side effects silently never happen while the run reports done.
        // Such calls still get a ledger row (cost/telemetry readers depend on it),
        // under a per-call key. A caller-supplied key keeps its dedup semantics.
        if ($request->hasTools() && $request->idempotencyKey === null) {
            return $this->run($request, $this->createLog($request, $request->generateIdempotencyKey().':'.Str::uuid7()->toString()), $next);
        }

        $idempotencyKey = $request->generateIdempotencyKey();

        // Check for existing record (withoutGlobalScopes for queue context)
        $existing = LlmRequestLog::withoutGlobalScopes()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            // Return cached response for completed requests
            if ($existing->status === 'completed') {
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

            // For pending/failed records, reset and retry
            $existing->update([
                'status' => 'pending',
                'error' => null,
                'team_id' => $request->teamId ?? $existing->team_id,
            ]);
            $log = $existing;
        } else {
            $log = $this->createLog($request, $idempotencyKey);
        }

        return $this->run($request, $log, $next);
    }

    private function createLog(AiRequestDTO $request, string $idempotencyKey): LlmRequestLog
    {
        // Create pending log entry (withoutGlobalScopes for queue context)
        return LlmRequestLog::withoutGlobalScopes()->create([
            'team_id' => $request->teamId,
            'user_id' => $request->userId,
            'idempotency_key' => $idempotencyKey,
            'agent_id' => $request->agentId,
            'experiment_id' => $request->experimentId,
            'experiment_stage_id' => $request->experimentStageId,
            'provider' => $request->provider,
            'model' => $request->model,
            'prompt_hash' => hash('xxh128', $request->systemPrompt.$request->userPrompt),
            'status' => 'pending',
        ]);
    }

    private function run(AiRequestDTO $request, LlmRequestLog $log, Closure $next): AiResponseDTO
    {
        try {
            $response = $next($request);

            $log->update([
                'status' => 'completed',
                'byok_source' => $this->resolveByokSource(),
                'response_body' => $response->parsedOutput ?? ['text' => $response->content],
                'input_tokens' => $response->usage->promptTokens,
                'output_tokens' => $response->usage->completionTokens,
                'cost_credits' => $response->usage->costCredits,
                'accurate_cost_credits' => $response->usage->accurateCostCredits,
                'latency_ms' => $response->latencyMs,
                'completed_at' => now(),
            ]);

            return $response;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'byok_source' => $this->resolveByokSource(),
                'error' => substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }

    private function resolveByokSource(): ?string
    {
        if (! app()->bound('ai.byok_source')) {
            return null;
        }

        $value = app('ai.byok_source');

        return is_string($value) ? $value : null;
    }
}
