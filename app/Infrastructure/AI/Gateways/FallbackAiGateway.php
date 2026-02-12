<?php

namespace App\Infrastructure\AI\Gateways;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Services\CircuitBreaker;
use Illuminate\Support\Facades\Log;
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
        private readonly ?LocalAgentGateway $localGateway = null,
    ) {}

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        // Route local agent requests directly — no fallback chain
        if ($this->isLocalProvider($request->provider)) {
            if (! $this->localGateway) {
                throw new \RuntimeException(
                    "Local agent provider '{$request->provider}' is not available. "
                    . 'Enable local agents (LOCAL_AGENTS_ENABLED=true) and ensure the agent binary is installed.'
                );
            }

            return $this->localGateway->complete($request);
        }

        $chain = $this->getFallbackChain($request->provider, $request->model, $request->fallbackChain);

        $lastException = null;

        foreach ($chain as $target) {
            $providerName = $target['provider'];
            $modelName = $target['model'];

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
                );

                $response = $this->gateway->complete($adjustedRequest);

                $this->circuitBreaker->recordSuccess($providerName);

                return $response;
            } catch (PrismRateLimitedException $e) {
                // Rate limits are temporary — don't break the circuit
                $lastException = $e;
                Log::warning("AI Gateway: {$providerName}/{$modelName} rate limited (not recording CB failure)", [
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable $e) {
                $lastException = $e;
                $this->circuitBreaker->recordFailure($providerName);
                Log::warning("AI Gateway fallback: {$providerName}/{$modelName} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastException ?? new \RuntimeException('No available providers in fallback chain');
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        if ($this->isLocalProvider($request->provider)) {
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

    private function isLocalProvider(string $provider): bool
    {
        // Explicit "local" provider name (generic alias)
        if ($provider === 'local') {
            return true;
        }

        return (bool) config("llm_providers.{$provider}.local");
    }
}
