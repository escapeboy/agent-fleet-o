<?php

namespace App\Infrastructure\AI\Gateways;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Services\CircuitBreaker;
use App\Infrastructure\AI\Services\EscalationStrategy;
use App\Infrastructure\Telemetry\TracerProvider as FleetTracerProvider;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Throwable;

class FallbackAiGateway implements AiGatewayInterface
{
    /**
     * @param  array<string, list<array{provider: string, model: string}>>  $fallbackChains
     */
    public function __construct(
        private readonly PrismAiGateway $gateway,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly array $fallbackChains = [],
        private readonly ?AiGatewayInterface $localGateway = null,
        private readonly ?LocalBridgeGateway $bridgeGateway = null,
        private readonly ?EscalationStrategy $escalationStrategy = null,
    ) {}

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $tracer = app(FleetTracerProvider::class)->tracer('fleetq.ai');
        $span = $tracer->spanBuilder('ai.gateway.complete')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('ai.gateway.requested_provider', $request->provider)
            ->setAttribute('ai.gateway.requested_model', $request->model)
            ->setAttribute('ai.gateway.team_id', (string) ($request->teamId ?? 'unknown'))
            ->setAttribute('ai.gateway.purpose', (string) ($request->purpose ?? 'unknown'))
            ->startSpan();
        $scope = $span->activate();

        try {
            $result = $this->completeWithFallback($request, $span);
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function completeWithFallback(AiRequestDTO $request, SpanInterface $span): AiResponseDTO
    {
        // Route local agent requests directly — no fallback chain
        if ($this->isLocalProvider($request->provider)) {
            if (! $this->localGateway) {
                throw new \RuntimeException(
                    "Local agent provider '{$request->provider}' is not available. "
                    .'Enable local agents (LOCAL_AGENTS_ENABLED=true) and ensure the agent binary is installed.',
                );
            }

            $span->setAttribute('ai.gateway.route', 'local');

            return $this->localGateway->complete($request);
        }

        $chain = $this->getFallbackChain($request->provider, $request->model, $request->fallbackChain);

        $lastException = null;
        $firstException = null;

        foreach ($chain as $target) {
            $providerName = $target['provider'];
            $modelName = $target['model'];

            // Bridge provider — route through bridge daemon, fall through to next on failure
            if ($this->isBridgeProvider($providerName)) {
                if (! $this->bridgeGateway) {
                    Log::warning("AI Gateway: bridge provider '{$providerName}' not available, trying next in chain");

                    continue;
                }

                try {
                    $adjustedRequest = new AiRequestDTO(
                        provider: $providerName,
                        model: $modelName,
                        systemPrompt: $request->systemPrompt,
                        userPrompt: $request->userPrompt,
                        maxTokens: $request->maxTokens,
                        outputSchema: $request->outputSchema,
                        userId: $request->userId,
                        experimentId: $request->experimentId,
                        experimentStageId: $request->experimentStageId,
                        agentId: $request->agentId,
                        purpose: $request->purpose,
                        idempotencyKey: $request->idempotencyKey,
                        temperature: $request->temperature,
                        teamId: $request->teamId,
                        fallbackChain: $request->fallbackChain,
                        tools: $request->tools,
                        maxSteps: $request->maxSteps,
                        toolChoice: $request->toolChoice,
                        thinkingBudget: $request->thinkingBudget,
                        effort: $request->effort,
                        workingDirectory: $request->workingDirectory,
                        enablePromptCaching: $request->enablePromptCaching,
                        complexity: $request->complexity,
                        classifiedComplexity: $request->classifiedComplexity,
                        budgetPressureLevel: $request->budgetPressureLevel,
                        escalationAttempts: $request->escalationAttempts,
                        fastMode: $request->fastMode,
                    );

                    $span->setAttribute('ai.gateway.route', 'bridge');
                    $span->setAttribute('ai.gateway.final_provider', $providerName);
                    $span->setAttribute('ai.gateway.final_model', $modelName);

                    return $this->bridgeGateway->complete($adjustedRequest);
                } catch (Throwable $e) {
                    $firstException ??= $e;
                    $lastException = $e;
                    Log::warning("AI Gateway: bridge provider '{$providerName}' failed, trying next in chain", [
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            if (! $this->circuitBreaker->isAvailable($providerName)) {
                Log::debug("CircuitBreaker: skipping {$providerName} (circuit open)");

                continue;
            }

            try {
                $adjustedRequest = new AiRequestDTO(
                    provider: $providerName,
                    model: $modelName,
                    systemPrompt: $request->systemPrompt,
                    userPrompt: $request->userPrompt,
                    maxTokens: $request->maxTokens,
                    outputSchema: $request->outputSchema,
                    userId: $request->userId,
                    experimentId: $request->experimentId,
                    experimentStageId: $request->experimentStageId,
                    agentId: $request->agentId,
                    purpose: $request->purpose,
                    idempotencyKey: $request->idempotencyKey,
                    temperature: $request->temperature,
                    teamId: $request->teamId,
                    fallbackChain: $request->fallbackChain,
                    tools: $request->tools,
                    maxSteps: $request->maxSteps,
                    toolChoice: $request->toolChoice,
                );

                $response = $this->gateway->complete($adjustedRequest);

                $this->circuitBreaker->recordSuccess($providerName);

                $span->setAttribute('ai.gateway.final_provider', $providerName);
                $span->setAttribute('ai.gateway.final_model', $modelName);
                $span->setAttribute('ai.gateway.fallback_used', $providerName !== $request->provider);

                return $response;
            } catch (PrismRateLimitedException $e) {
                // Rate limits are temporary — don't break the circuit
                $firstException ??= $e;
                $lastException = $e;
                Log::warning("AI Gateway: {$providerName}/{$modelName} rate limited (not recording CB failure)", [
                    'error' => $e->getMessage(),
                ]);
            } catch (\RuntimeException $e) {
                // Missing BYOK / plan restriction — config error, not a real provider failure.
                // Don't record a circuit breaker failure for this. Store as last but preserve first.
                $firstException ??= $e;
                $lastException = $e;
                Log::warning("AI Gateway fallback: {$providerName}/{$modelName} config error (not recording CB failure)", [
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                $firstException ??= $e;
                $lastException = $e;
                $this->circuitBreaker->recordFailure($providerName);
                Log::warning("AI Gateway fallback: {$providerName}/{$modelName} failed", [
                    'error' => $e->getMessage(),
                ]);

                // Try model-tier escalation for quality failures before moving to next provider
                $escalatedResponse = $this->attemptEscalation($request, $e, $providerName, $modelName);
                if ($escalatedResponse !== null) {
                    return $escalatedResponse;
                }
            }
        }

        // Throw the first exception so the user sees the root cause (e.g. rate limit),
        // not a misleading error from a fallback provider they didn't configure.
        throw $firstException ?? new \RuntimeException('No available providers in fallback chain');
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        $tracer = app(FleetTracerProvider::class)->tracer('fleetq.ai');
        $span = $tracer->spanBuilder('ai.gateway.stream')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('ai.gateway.requested_provider', $request->provider)
            ->setAttribute('ai.gateway.requested_model', $request->model)
            ->setAttribute('ai.gateway.team_id', (string) ($request->teamId ?? 'unknown'))
            ->setAttribute('ai.gateway.purpose', (string) ($request->purpose ?? 'unknown'))
            ->startSpan();
        $scope = $span->activate();

        try {
            $result = $this->streamWithFallback($request, $onChunk, $span);
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function streamWithFallback(AiRequestDTO $request, ?callable $onChunk, SpanInterface $span): AiResponseDTO
    {
        // Route local agent requests directly — no fallback chain
        if ($this->isLocalProvider($request->provider)) {
            if (! $this->localGateway) {
                throw new \RuntimeException(
                    "Local agent provider '{$request->provider}' is not available. "
                    .'Enable local agents (LOCAL_AGENTS_ENABLED=true) and ensure the agent binary is installed.',
                );
            }

            $span->setAttribute('ai.gateway.route', 'local');

            return $this->localGateway->stream($request, $onChunk);
        }

        $chain = $this->getFallbackChain($request->provider, $request->model, $request->fallbackChain);
        $firstException = null;
        $lastException = null;

        foreach ($chain as $target) {
            $providerName = $target['provider'];
            $modelName = $target['model'];

            // Bridge provider — route through bridge daemon, fall through to next on failure
            if ($this->isBridgeProvider($providerName)) {
                if (! $this->bridgeGateway) {
                    Log::warning("AI Gateway: bridge provider '{$providerName}' not available, trying next in chain");

                    continue;
                }

                try {
                    $adjustedRequest = new AiRequestDTO(
                        provider: $providerName,
                        model: $modelName,
                        systemPrompt: $request->systemPrompt,
                        userPrompt: $request->userPrompt,
                        maxTokens: $request->maxTokens,
                        outputSchema: $request->outputSchema,
                        userId: $request->userId,
                        experimentId: $request->experimentId,
                        experimentStageId: $request->experimentStageId,
                        agentId: $request->agentId,
                        purpose: $request->purpose,
                        idempotencyKey: $request->idempotencyKey,
                        temperature: $request->temperature,
                        teamId: $request->teamId,
                        fallbackChain: $request->fallbackChain,
                        tools: $request->tools,
                        maxSteps: $request->maxSteps,
                        toolChoice: $request->toolChoice,
                        thinkingBudget: $request->thinkingBudget,
                        effort: $request->effort,
                        workingDirectory: $request->workingDirectory,
                        enablePromptCaching: $request->enablePromptCaching,
                        complexity: $request->complexity,
                        classifiedComplexity: $request->classifiedComplexity,
                        budgetPressureLevel: $request->budgetPressureLevel,
                        escalationAttempts: $request->escalationAttempts,
                        fastMode: $request->fastMode,
                    );

                    $span->setAttribute('ai.gateway.route', 'bridge');
                    $span->setAttribute('ai.gateway.final_provider', $providerName);
                    $span->setAttribute('ai.gateway.final_model', $modelName);

                    return $this->bridgeGateway->stream($adjustedRequest, $onChunk);
                } catch (Throwable $e) {
                    $firstException ??= $e;
                    $lastException = $e;
                    Log::warning("AI Gateway: bridge provider '{$providerName}' failed, trying next in chain", [
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            if (! $this->circuitBreaker->isAvailable($providerName)) {
                continue;
            }

            try {
                $adjustedRequest = new AiRequestDTO(
                    provider: $providerName,
                    model: $modelName,
                    systemPrompt: $request->systemPrompt,
                    userPrompt: $request->userPrompt,
                    maxTokens: $request->maxTokens,
                    outputSchema: $request->outputSchema,
                    userId: $request->userId,
                    experimentId: $request->experimentId,
                    experimentStageId: $request->experimentStageId,
                    agentId: $request->agentId,
                    purpose: $request->purpose,
                    idempotencyKey: $request->idempotencyKey,
                    temperature: $request->temperature,
                    teamId: $request->teamId,
                    fallbackChain: $request->fallbackChain,
                    tools: $request->tools,
                    maxSteps: $request->maxSteps,
                    toolChoice: $request->toolChoice,
                );

                $response = $this->gateway->stream($adjustedRequest, $onChunk);
                $this->circuitBreaker->recordSuccess($providerName);

                $span->setAttribute('ai.gateway.final_provider', $providerName);
                $span->setAttribute('ai.gateway.final_model', $modelName);
                $span->setAttribute('ai.gateway.fallback_used', $providerName !== $request->provider);

                return $response;
            } catch (\RuntimeException $e) {
                $firstException ??= $e;
                $lastException = $e;
                Log::warning("AI Gateway stream fallback: {$providerName}/{$modelName} config error", [
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                $firstException ??= $e;
                $lastException = $e;
                $this->circuitBreaker->recordFailure($providerName);

                // Try model-tier escalation for quality failures before moving to next provider
                $escalatedResponse = $this->attemptStreamEscalation($request, $e, $providerName, $modelName, $onChunk);
                if ($escalatedResponse !== null) {
                    return $escalatedResponse;
                }
            }
        }

        throw $firstException ?? new \RuntimeException('No available providers in fallback chain');
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        if ($this->isBridgeProvider($request->provider) || $this->isLocalProvider($request->provider)) {
            return 0;
        }

        return $this->gateway->estimateCost($request);
    }

    /**
     * @return list<array{provider: string, model: string}>
     */
    private function getFallbackChain(string $provider, string $model, ?array $requestChain = null): array
    {
        $chain = [['provider' => $provider, 'model' => $model]];

        // Per-request fallback chain takes priority over global chains
        if ($requestChain) {
            return array_merge($chain, $requestChain);
        }

        $key = "{$provider}/{$model}";

        if (isset($this->fallbackChains[$key])) {
            $chain = array_merge($chain, $this->fallbackChains[$key]);
        }

        return $chain;
    }

    private function isBridgeProvider(string $provider): bool
    {
        return (bool) config("llm_providers.{$provider}.bridge");
    }

    private function isLocalProvider(string $provider): bool
    {
        // Explicit "local" provider name (generic alias)
        if ($provider === 'local') {
            return true;
        }

        return (bool) config("llm_providers.{$provider}.local");
    }

    /**
     * Attempt model-tier escalation for quality failures (complete path).
     */
    private function attemptEscalation(
        AiRequestDTO $request,
        Throwable $exception,
        string $providerName,
        string $modelName,
    ): ?AiResponseDTO {
        if (! $this->escalationStrategy) {
            return null;
        }

        $failureType = $this->escalationStrategy->classifyFailure($exception);

        if (! $failureType->shouldEscalateModel()) {
            return null;
        }

        $escalated = $this->escalationStrategy->getEscalatedModel($request, $providerName, $modelName);

        if ($escalated === null) {
            return null;
        }

        try {
            $escalatedRequest = $this->buildEscalatedRequest($request, $escalated['provider'], $escalated['model']);
            $response = $this->gateway->complete($escalatedRequest);
            $this->circuitBreaker->recordSuccess($escalated['provider']);

            return $response;
        } catch (Throwable $e) {
            Log::warning("AI Gateway: escalation to {$escalated['provider']}/{$escalated['model']} failed", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Attempt model-tier escalation for quality failures (stream path).
     */
    private function attemptStreamEscalation(
        AiRequestDTO $request,
        Throwable $exception,
        string $providerName,
        string $modelName,
        ?callable $onChunk,
    ): ?AiResponseDTO {
        if (! $this->escalationStrategy) {
            return null;
        }

        $failureType = $this->escalationStrategy->classifyFailure($exception);

        if (! $failureType->shouldEscalateModel()) {
            return null;
        }

        $escalated = $this->escalationStrategy->getEscalatedModel($request, $providerName, $modelName);

        if ($escalated === null) {
            return null;
        }

        try {
            $escalatedRequest = $this->buildEscalatedRequest($request, $escalated['provider'], $escalated['model']);
            $response = $this->gateway->stream($escalatedRequest, $onChunk);
            $this->circuitBreaker->recordSuccess($escalated['provider']);

            return $response;
        } catch (Throwable $e) {
            Log::warning("AI Gateway: stream escalation to {$escalated['provider']}/{$escalated['model']} failed", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a new AiRequestDTO with escalated provider/model and incremented attempts.
     */
    private function buildEscalatedRequest(AiRequestDTO $request, string $provider, string $model): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $request->systemPrompt,
            userPrompt: $request->userPrompt,
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
            thinkingBudget: $request->thinkingBudget,
            workingDirectory: $request->workingDirectory,
            enablePromptCaching: $request->enablePromptCaching,
            complexity: $request->complexity,
            classifiedComplexity: $request->classifiedComplexity,
            budgetPressureLevel: $request->budgetPressureLevel,
            escalationAttempts: $request->escalationAttempts + 1,
        );
    }
}
