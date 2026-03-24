<?php

namespace App\Infrastructure\AI\Gateways;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Services\BridgeRouter;
use App\Domain\Credential\Actions\ResolveProjectCredentialsAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Gateways\HttpBridgeGateway;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Exceptions\BridgeExecutionException;
use App\Infrastructure\AI\Exceptions\BridgeTimeoutException;
use App\Infrastructure\Bridge\BridgeRequestRegistry;
use App\Infrastructure\Bridge\Events\BridgeAgentRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Sentry\Severity;
use Sentry\State\Scope;

class LocalBridgeGateway implements AiGatewayInterface
{
    private const RELAY_TIMEOUT = 1200;

    public function __construct(
        private readonly BridgeRequestRegistry $registry,
        private readonly BridgeRouter $router,
        private readonly HttpBridgeGateway $httpGateway,
    ) {}

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $connection = $this->requireActiveConnection($request->teamId, $request);

        if ($connection->isHttpMode()) {
            return $this->httpGateway->complete($request);
        }

        return $this->routeRequest($connection, $request);
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        $connection = $this->requireActiveConnection($request->teamId, $request);

        if ($connection->isHttpMode()) {
            return $this->httpGateway->stream($request, $onChunk);
        }

        return $this->routeRequest($connection, $request, $onChunk);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0; // Bridge requests are always zero cost
    }

    /**
     * Resolve the best active bridge connection for the given request.
     *
     * For bridge_agent requests, uses BridgeRouter to resolve based on
     * the team's routing preferences (auto/prefer/per_agent).
     * For bridge_llm requests, picks the best connection with matching LLM endpoints.
     */
    private function requireActiveConnection(?string $teamId, ?AiRequestDTO $request = null): BridgeConnection
    {
        if (! $teamId) {
            throw new RuntimeException(
                'FleetQ Bridge: No team context available. Ensure the request includes a team ID.',
            );
        }

        // For bridge_agent, use routing preferences to pick the right connection
        if ($request && $request->provider === 'bridge_agent') {
            $agentKey = explode(':', $request->model, 2)[0];
            $connection = $this->router->resolveForAgent($teamId, $agentKey);

            if ($connection) {
                return $connection;
            }
        }

        // Fallback: pick the best active connection (highest priority, most recent)
        $connection = BridgeConnection::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->active()
            ->orderByDesc('priority')
            ->orderByDesc('connected_at')
            ->first();

        if (! $connection) {
            throw new RuntimeException(
                'FleetQ Bridge is not connected. '
                .'Download and start the bridge daemon: https://github.com/escapeboy/fleetq-bridge',
            );
        }

        return $connection;
    }

    private function routeRequest(BridgeConnection $connection, AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        $startedAt = now();
        $requestId = Str::uuid()->toString();

        // Register in-flight request so the relay binary can push chunks into it
        $this->registry->register($requestId, $request->teamId);

        $payload = $this->buildPayload($requestId, $request, $connection);

        // Push the request to the Redis queue — the relay binary reads via BLPOP
        // and forwards to the bridge daemon over the WebSocket connection.
        Redis::connection('bridge')->rpush(
            "bridge:req:{$request->teamId}",
            json_encode($payload),
        );

        // Also broadcast via Reverb so bridge daemons using the `start` command
        // (connected to Reverb directly, not the relay) receive the request.
        try {
            broadcast(new BridgeAgentRequest($request->teamId, $payload['payload'] ?? $payload));
        } catch (\Throwable $e) {
            Log::warning('LocalBridgeGateway: Reverb broadcast failed, relying on relay path', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }

        // Consume the Redis chunk stream via BLPOP
        $content = '';
        $promptTokens = 0;
        $completionTokens = 0;

        while (true) {
            $item = $this->registry->popChunk($requestId, self::RELAY_TIMEOUT);

            if ($item === null) {
                \Sentry\withScope(function (Scope $scope) use ($requestId, $request, $content): void {
                    $scope->setTag('provider', $request->provider);
                    $scope->setTag('model', $request->model);
                    $scope->setContext('bridge_timeout', [
                        'request_id' => $requestId,
                        'provider' => $request->provider,
                        'model' => $request->model,
                        'team_id' => $request->teamId,
                        'timeout_seconds' => self::RELAY_TIMEOUT,
                        'content_received_so_far' => strlen($content),
                    ]);
                    \Sentry\captureMessage(
                        "Bridge relay timed out: {$request->provider}/{$request->model} ({$requestId})",
                        Severity::error(),
                    );
                });

                throw new BridgeTimeoutException($requestId, self::RELAY_TIMEOUT);
            }

            $chunk = $item['chunk'] ?? '';

            if ($onChunk !== null && $chunk !== '') {
                $onChunk($chunk);
            }

            $content .= $chunk;

            if ($item['done'] ?? false) {
                // Check for error sentinel stored by HandleBridgeRelayResponse
                $usage = $this->registry->getUsage($requestId);

                if (isset($usage['__error'])) {
                    \Sentry\withScope(function (Scope $scope) use ($requestId, $request, $usage): void {
                        $scope->setTag('provider', $request->provider);
                        $scope->setTag('model', $request->model);
                        $scope->setContext('bridge_execution_error', [
                            'request_id' => $requestId,
                            'provider' => $request->provider,
                            'model' => $request->model,
                            'team_id' => $request->teamId,
                            'error' => $usage['__error'],
                        ]);
                        \Sentry\captureMessage(
                            "Bridge execution error: {$request->provider}/{$request->model} — {$usage['__error']}",
                            Severity::error(),
                        );
                    });

                    throw new BridgeExecutionException($usage['__error'], $requestId);
                }

                if ($usage) {
                    $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
                    $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
                }

                break;
            }
        }

        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costCredits: 0,
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: (int) $startedAt->diffInMilliseconds(now()),
        );
    }

    private function buildPayload(string $requestId, AiRequestDTO $request, BridgeConnection $connection): array
    {
        // bridge_agent → FrameAgentRequest (0x0010 = 16)
        // The model field may be a compound "agent_key:model" (e.g. "claude-code:claude-sonnet-4-5")
        // or just "agent_key" when no per-agent model was selected.
        if ($request->provider === 'bridge_agent') {
            $parts = explode(':', $request->model, 2);
            $agentKey = $parts[0];
            $agentModel = $parts[1] ?? '';

            $payload = [
                'request_id' => $requestId,
                'agent_key' => $agentKey,
                'model' => $agentModel, // passed as --model to the agent CLI
                'prompt' => $request->userPrompt ?? '',
                'system_prompt' => $request->systemPrompt ?? '',
                'purpose' => $request->purpose ?? '',
                'stream' => true,
            ];

            // Inject credentials as env vars so the bridge agent can authenticate
            // with external services (Reddit, APIs, etc.) via curl/bash.
            $env = $this->resolveAgentCredentialEnv($request->agentId);
            if (! empty($env)) {
                $payload['env'] = $env;

                // Append env var reference to system prompt so the agent knows
                // credentials are available in its environment, not via API callbacks.
                $envVarList = collect($env)
                    ->keys()
                    ->map(fn (string $k) => "- `\${$k}`")
                    ->implode("\n");
                $payload['system_prompt'] .= "\n\n## Credentials (Environment Variables)\n"
                    .'The following credentials are injected into your environment as env vars. '
                    ."Access them via bash (e.g. `echo \$CRED_...`). Do NOT try to call any API to fetch credentials.\n\n"
                    .$envVarList;
            }

            return [
                'request_id' => $requestId,
                'frame_type' => 0x0010,
                'payload' => $payload,
            ];
        }

        // bridge_llm → FrameLLMRequest (0x0001 = 1)
        // Build OpenAI-compatible messages array from system/user prompts
        $messages = [];
        if ($request->systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }
        if ($request->userPrompt) {
            $messages[] = ['role' => 'user', 'content' => $request->userPrompt];
        }

        return [
            'request_id' => $requestId,
            'frame_type' => 0x0001,
            'payload' => [
                'request_id' => $requestId,
                'endpoint_url' => $this->resolveEndpointUrl($request->model, $connection),
                'model' => $request->model,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens ?? 8192,
                'temperature' => $request->temperature ?? 0.7,
                'stream' => true,
            ],
        ];
    }

    /**
     * Resolve credentials from the agent's attached tools and flatten into env vars.
     *
     * Delegates to ResolveProjectCredentialsAction::resolveAsEnvMap() so the logic
     * is shared with non-bridge bash tool execution.
     *
     * @return array<string, string>
     */
    private function resolveAgentCredentialEnv(?string $agentId): array
    {
        if (! $agentId) {
            return [];
        }

        return app(ResolveProjectCredentialsAction::class)->resolveAsEnvMap($agentId);
    }

    /**
     * Find the base_url of a discovered LLM endpoint by matching model name against
     * each endpoint's models list, falling back to the first online endpoint.
     */
    private function resolveEndpointUrl(string $model, BridgeConnection $connection): string
    {
        $endpoints = $connection->llmEndpoints();

        // Match by model name in endpoint's models array
        foreach ($endpoints as $ep) {
            if (($ep['online'] ?? false) && in_array($model, $ep['models'] ?? [], true)) {
                return $ep['base_url'];
            }
        }

        // Fall back to first online endpoint
        foreach ($endpoints as $ep) {
            if ($ep['online'] ?? false) {
                return $ep['base_url'];
            }
        }

        // Last resort — Ollama default
        return 'http://localhost:11434';
    }
}
