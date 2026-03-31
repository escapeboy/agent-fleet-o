<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Services\ContextCompactor;
use App\Infrastructure\AI\Services\TokenEstimator;
use Closure;
use Illuminate\Support\Facades\Log;

class ContextCompaction implements AiMiddlewareInterface
{
    public function __construct(
        private readonly TokenEstimator $tokenEstimator,
        private readonly ContextCompactor $compactor,
    ) {}

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        // Per-team override, then config fallback
        try {
            $team = $request->teamId ? Team::withoutGlobalScopes()->find($request->teamId) : null;
        } catch (\Throwable) {
            $team = null; // Unit tests may not have teams table
        }
        $enabled = $team?->settings['context_compaction_enabled'] ?? config('context_compaction.enabled', true);

        if (! $enabled) {
            return $next($request);
        }

        // Skip for local agents (zero-cost, context managed locally)
        if (str_starts_with($request->provider, 'local/') || $request->provider === 'bridge_agent') {
            return $next($request);
        }

        $utilization = $this->tokenEstimator->calculateUtilization(
            $request->systemPrompt,
            $request->userPrompt,
            $request->model,
        );

        $summarizeThreshold = (float) config('context_compaction.summarize_threshold', 0.70);

        // Green zone — pass through
        if ($utilization < $summarizeThreshold) {
            return $next($request);
        }

        $startTime = hrtime(true);
        $tokensBefore = $this->tokenEstimator->estimateRequest($request->systemPrompt, $request->userPrompt);

        $modelLimit = $this->tokenEstimator->getModelContextLimit($request->model);
        $targetUtilization = (float) config('context_compaction.target_utilization', 0.55);
        $targetTokens = (int) ($modelLimit * $targetUtilization);

        [$compactedUserPrompt, $stageReached] = $this->compactor->compact(
            systemPrompt: $request->systemPrompt,
            userPrompt: $request->userPrompt,
            targetTokens: $targetTokens,
            utilization: $utilization,
            summarizationModel: config('context_compaction.summarizer_model', 'anthropic/claude-haiku-4-5'),
            summarizationMaxTokens: (int) config('context_compaction.summarizer_max_tokens', 2000),
            minPreservedLines: (int) config('context_compaction.min_preserved_turns', 4),
        );

        $tokensAfter = $this->tokenEstimator->estimateRequest($request->systemPrompt, $compactedUserPrompt);
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        Log::info('ContextCompaction: compacted request', [
            'provider' => $request->provider,
            'model' => $request->model,
            'purpose' => $request->purpose,
            'utilization_before' => round($utilization, 3),
            'utilization_after' => round($tokensAfter / $modelLimit, 3),
            'tokens_before' => $tokensBefore,
            'tokens_after' => $tokensAfter,
            'tokens_saved' => $tokensBefore - $tokensAfter,
            'stage_reached' => $stageReached,
            'duration_ms' => $durationMs,
        ]);

        // Create a new request with the compacted user prompt
        $compactedRequest = new AiRequestDTO(
            provider: $request->provider,
            model: $request->model,
            systemPrompt: $request->systemPrompt,
            userPrompt: $compactedUserPrompt,
            maxTokens: $request->maxTokens,
            outputSchema: $request->outputSchema,
            userId: $request->userId,
            teamId: $request->teamId,
            experimentId: $request->experimentId,
            experimentStageId: $request->experimentStageId,
            agentId: $request->agentId,
            purpose: $request->purpose,
            idempotencyKey: $request->idempotencyKey,
            temperature: $request->temperature,
            fallbackChain: $request->fallbackChain,
            tools: $request->tools,
            maxSteps: $request->maxSteps,
            toolChoice: $request->toolChoice,
            providerName: $request->providerName,
        );

        return $next($compactedRequest);
    }
}
