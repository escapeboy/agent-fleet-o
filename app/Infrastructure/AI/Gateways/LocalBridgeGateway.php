<?php

namespace App\Infrastructure\AI\Gateways;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Exceptions\BridgeExecutionException;
use App\Infrastructure\AI\Exceptions\BridgeTimeoutException;
use App\Infrastructure\Bridge\BridgeRequestRegistry;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

class LocalBridgeGateway implements AiGatewayInterface
{
    private const RELAY_TIMEOUT = 90;

    public function __construct(private readonly BridgeRequestRegistry $registry) {}

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $connection = $this->requireActiveConnection($request->teamId);

        return $this->routeRequest($connection, $request);
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        $connection = $this->requireActiveConnection($request->teamId);

        return $this->routeRequest($connection, $request, $onChunk);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0; // Bridge requests are always zero cost
    }

    private function requireActiveConnection(?string $teamId): BridgeConnection
    {
        if (! $teamId) {
            throw new RuntimeException(
                'FleetQ Bridge: No team context available. Ensure the request includes a team ID.'
            );
        }

        $connection = BridgeConnection::where('team_id', $teamId)
            ->active()
            ->orderByDesc('connected_at')
            ->first();

        if (! $connection) {
            throw new RuntimeException(
                'FleetQ Bridge is not connected. '
                .'Download and start the bridge daemon: https://github.com/fleetq/fleetq-bridge'
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

        // Push the request to the Redis queue — the relay binary reads via BLPOP
        // and forwards to the bridge daemon over the WebSocket connection.
        Redis::connection('bridge')->rpush(
            "bridge:req:{$request->teamId}",
            json_encode($this->buildPayload($requestId, $request)),
        );

        // Consume the Redis chunk stream via BLPOP
        $content = '';
        $promptTokens = 0;
        $completionTokens = 0;

        while (true) {
            $item = $this->registry->popChunk($requestId, self::RELAY_TIMEOUT);

            if ($item === null) {
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

    private function buildPayload(string $requestId, AiRequestDTO $request): array
    {
        return [
            'request_id' => $requestId,
            'type' => 'llm',
            'provider' => $request->provider,
            'model' => $request->model,
            'system_prompt' => $request->systemPrompt,
            'user_prompt' => $request->userPrompt,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'tools' => [],
        ];
    }
}
