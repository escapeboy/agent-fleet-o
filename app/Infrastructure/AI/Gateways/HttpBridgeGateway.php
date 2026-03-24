<?php

namespace App\Infrastructure\AI\Gateways;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Services\BridgeRouter;
use App\Domain\Credential\Actions\ResolveProjectCredentialsAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Exceptions\BridgeExecutionException;
use App\Infrastructure\AI\Exceptions\BridgeTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class HttpBridgeGateway implements AiGatewayInterface
{
    private const EXECUTION_TIMEOUT = 1200;

    private const CONNECT_TIMEOUT = 15;

    public function __construct(
        private readonly BridgeRouter $router,
    ) {}

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        return $this->execute($request, null);
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        return $this->execute($request, $onChunk);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0;
    }

    private function execute(AiRequestDTO $request, ?callable $onChunk): AiResponseDTO
    {
        $startedAt = now();
        $requestId = Str::uuid()->toString();

        $connection = $this->requireActiveConnection($request->teamId, $request);
        $url = rtrim($connection->endpoint_url, '/').'/execute';
        $payload = $this->buildPayload($requestId, $request, $connection);
        $headers = $this->authHeaders($connection);

        $content = '';
        $promptTokens = 0;
        $completionTokens = 0;
        $errorMessage = null;

        try {
            $response = Http::timeout(self::EXECUTION_TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->withHeaders($headers)
                ->withOptions(['stream' => true])
                ->post($url, $payload);

            if (! $response->successful()) {
                throw new BridgeExecutionException(
                    "HTTP {$response->status()}: {$response->body()}",
                    $requestId,
                );
            }

            $body = $response->toPsrResponse()->getBody();
            $buffer = '';

            while (! $body->eof()) {
                $raw = $body->read(8192);

                if ($raw === '') {
                    continue;
                }

                $buffer .= $raw;

                // Process complete lines from the buffer
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);

                    // Skip SSE comments (keepalive: ": ...") and blank lines
                    if ($line === '' || str_starts_with($line, ':')) {
                        continue;
                    }

                    if (! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = json_decode(substr($line, 6), true);

                    if (! is_array($data)) {
                        continue;
                    }

                    if (isset($data['error'])) {
                        $errorMessage = $data['error'];

                        continue;
                    }

                    $chunkText = $data['chunk'] ?? '';

                    if ($chunkText !== '' && $onChunk !== null) {
                        $onChunk($chunkText);
                    }

                    $content .= $chunkText;

                    if ($data['done'] ?? false) {
                        $usage = $data['usage'] ?? [];
                        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
                        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
                    }
                }
            }
        } catch (ConnectionException $e) {
            Log::warning('HttpBridgeGateway: connection failed', [
                'request_id' => $requestId,
                'endpoint_url' => $connection->endpoint_url,
                'error' => $e->getMessage(),
            ]);

            throw new BridgeTimeoutException($requestId, self::CONNECT_TIMEOUT);
        }

        if ($errorMessage !== null) {
            throw new BridgeExecutionException($errorMessage, $requestId);
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

    private function requireActiveConnection(?string $teamId, AiRequestDTO $request): BridgeConnection
    {
        if (! $teamId) {
            throw new RuntimeException(
                'HttpBridgeGateway: No team context available. Ensure the request includes a team ID.',
            );
        }

        if ($request->provider === 'bridge_agent') {
            $agentKey = explode(':', $request->model, 2)[0];
            $connection = $this->router->resolveForAgent($teamId, $agentKey);

            if ($connection && $connection->isHttpMode()) {
                return $connection;
            }
        }

        $connection = BridgeConnection::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNotNull('endpoint_url')
            ->active()
            ->orderByDesc('priority')
            ->orderByDesc('connected_at')
            ->first();

        if (! $connection) {
            throw new RuntimeException(
                'No active HTTP bridge connection found. '
                .'Start your local bridge server, run a tunnel (Cloudflare/Tailscale/ngrok), '
                .'and register the URL in FleetQ Settings → Bridge.',
            );
        }

        return $connection;
    }

    /** @return array<string, string> */
    private function authHeaders(BridgeConnection $connection): array
    {
        if (empty($connection->endpoint_secret)) {
            return [];
        }

        return ['Authorization' => 'Bearer '.$connection->endpoint_secret];
    }

    private function buildPayload(string $requestId, AiRequestDTO $request, BridgeConnection $connection): array
    {
        if ($request->provider === 'bridge_agent') {
            $parts = explode(':', $request->model, 2);
            $agentKey = $parts[0];
            $agentModel = $parts[1] ?? '';

            $payload = [
                'request_id' => $requestId,
                'agent_key' => $agentKey,
                'model' => $agentModel,
                'prompt' => $request->userPrompt ?? '',
                'system_prompt' => $request->systemPrompt ?? '',
                'purpose' => $request->purpose ?? '',
                'stream' => true,
            ];

            $env = $this->resolveAgentCredentialEnv($request->agentId);

            if (! empty($env)) {
                $payload['env'] = $env;

                $envVarList = collect($env)
                    ->keys()
                    ->map(fn (string $k) => "- `\${$k}`")
                    ->implode("\n");
                $payload['system_prompt'] .= "\n\n## Credentials (Environment Variables)\n"
                    .'The following credentials are injected into your environment as env vars. '
                    ."Access them via bash (e.g. `echo \$CRED_...`). Do NOT try to call any API to fetch credentials.\n\n"
                    .$envVarList;
            }

            return $payload;
        }

        // bridge_llm — OpenAI-compatible messages
        $messages = [];

        if ($request->systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        if ($request->userPrompt) {
            $messages[] = ['role' => 'user', 'content' => $request->userPrompt];
        }

        return [
            'request_id' => $requestId,
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? 8192,
            'temperature' => $request->temperature ?? 0.7,
            'stream' => true,
        ];
    }

    /** @return array<string, string> */
    private function resolveAgentCredentialEnv(?string $agentId): array
    {
        if (! $agentId) {
            return [];
        }

        return app(ResolveProjectCredentialsAction::class)->resolveAsEnvMap($agentId);
    }
}
