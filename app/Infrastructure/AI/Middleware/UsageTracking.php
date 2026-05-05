<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Agent\Models\AiRun;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use Closure;

class UsageTracking implements AiMiddlewareInterface
{
    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        $response = $next($request);

        if ($response->cached) {
            return $response; // Don't track cached responses as new runs
        }

        $hasReasoning = ! empty($response->reasoningChain);

        AiRun::withoutGlobalScopes()->create([
            'team_id' => $request->teamId,
            'agent_id' => $request->agentId,
            'experiment_id' => $request->experimentId,
            'experiment_stage_id' => $request->experimentStageId,
            'purpose' => $request->purpose ?? '',
            'provider' => $response->provider,
            'model' => $response->model,
            'input_schema' => $request->outputSchema ? ['name' => $request->outputSchema->name] : null,
            'prompt_snapshot' => [
                'system' => $request->systemPrompt,
                'user' => $request->userPrompt,
            ],
            'raw_output' => $response->parsedOutput ?? ['text' => $response->content],
            'parsed_output' => $response->parsedOutput,
            'schema_valid' => $response->schemaValid,
            'input_tokens' => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
            'cached_input_tokens' => $response->usage->cachedInputTokens,
            'cache_strategy' => $response->usage->cacheStrategy,
            'cost_credits' => $response->usage->costCredits,
            'latency_ms' => $response->latencyMs,
            'status' => 'completed',
            'reasoning_chain' => $response->reasoningChain,
            'has_reasoning' => $hasReasoning,
            'classified_complexity' => $request->classifiedComplexity?->value,
            'budget_pressure_level' => $request->budgetPressureLevel?->value,
            'escalation_attempts' => $request->escalationAttempts,
        ]);

        return $response;
    }
}
