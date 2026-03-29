<?php

namespace App\Infrastructure\AI\Gateways;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Portkey AI Gateway — routes all AI requests through https://api.portkey.ai/v1.
 *
 * Portkey is an OpenAI-compatible proxy that provides observability, caching,
 * fallbacks, and routing across 250+ LLM providers. Teams opt-in by storing
 * a Portkey API key as a TeamProviderCredential with provider='portkey'.
 *
 * Optional virtual keys (provider-specific keys managed in the Portkey dashboard)
 * can be stored under credentials['virtual_key'] and are forwarded via the
 * x-portkey-virtual-key header.
 */
class PortkeyGateway implements AiGatewayInterface
{
    private const BASE_URL = 'https://api.portkey.ai/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $virtualKey = null,
    ) {}

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $start = hrtime(true);

        $payload = $this->buildPayload($request);
        $response = $this->post('/chat/completions', $payload);

        $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

        return $this->buildResponse($response, $request, $latencyMs);
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        // Structured output and tool calling don't support streaming — fall back
        if ($request->isStructured() || $request->hasTools()) {
            return $this->complete($request);
        }

        $start = hrtime(true);

        $payload = $this->buildPayload($request);
        $payload['stream'] = true;

        $client = $this->makeClient();
        $headers = $this->buildHeaders();

        try {
            $guzzleResponse = $client->post(self::BASE_URL.'/chat/completions', [
                'headers' => $headers,
                'json' => $payload,
                'stream' => true,
            ]);
        } catch (BadResponseException $e) {
            $body = (string) $e->getResponse()->getBody();
            throw new RuntimeException(
                'Portkey API error '.$e->getResponse()->getStatusCode().': '.$body,
            );
        }

        $body = $guzzleResponse->getBody();
        $content = '';
        $promptTokens = 0;
        $completionTokens = 0;

        while (! $body->eof()) {
            $line = rtrim($body->read(4096));

            foreach (explode("\n", $line) as $rawLine) {
                $rawLine = trim($rawLine);
                if (! str_starts_with($rawLine, 'data: ')) {
                    continue;
                }

                $data = substr($rawLine, 6);
                if ($data === '[DONE]') {
                    break 2;
                }

                $chunk = json_decode($data, true);
                if (! is_array($chunk)) {
                    continue;
                }

                $delta = $chunk['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $content .= $delta;
                    if ($onChunk !== null) {
                        $onChunk($delta);
                    }
                }

                // Some providers send usage in the final SSE chunk
                if (isset($chunk['usage'])) {
                    $promptTokens = $chunk['usage']['prompt_tokens'] ?? 0;
                    $completionTokens = $chunk['usage']['completion_tokens'] ?? 0;
                }
            }
        }

        $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $usage = new AiUsageDTO(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            costCredits: 0,
        );

        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: $usage,
            provider: 'portkey',
            model: $request->model,
            latencyMs: $latencyMs,
        );
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        // Portkey manages cost accounting — we return 0 to avoid double-billing.
        return 0;
    }

    /**
     * Build the OpenAI-compatible chat completions payload.
     */
    private function buildPayload(AiRequestDTO $request): array
    {
        $messages = [];

        if ($request->systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $request->userPrompt];

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
        ];

        return $payload;
    }

    /**
     * POST to the Portkey API and return the decoded response body.
     */
    private function post(string $path, array $payload): array
    {
        $client = $this->makeClient();
        $headers = $this->buildHeaders();

        try {
            $response = $client->post(self::BASE_URL.$path, [
                'headers' => $headers,
                'json' => $payload,
            ]);
        } catch (BadResponseException $e) {
            $body = (string) $e->getResponse()->getBody();
            throw new RuntimeException(
                'Portkey API error '.$e->getResponse()->getStatusCode().': '.$body,
            );
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            throw new RuntimeException('Portkey API returned non-JSON response.');
        }

        return $data;
    }

    private function makeClient(): Client
    {
        return new Client(['timeout' => 120]);
    }

    /**
     * Build required and optional Portkey request headers.
     */
    private function buildHeaders(): array
    {
        $headers = [
            'x-portkey-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($this->virtualKey !== null && $this->virtualKey !== '') {
            $headers['x-portkey-virtual-key'] = $this->virtualKey;
        }

        return $headers;
    }

    /**
     * Map a Portkey/OpenAI chat completions response to AiResponseDTO.
     */
    private function buildResponse(array $data, AiRequestDTO $request, int $latencyMs): AiResponseDTO
    {
        $content = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? [];

        $aiUsage = new AiUsageDTO(
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
            costCredits: 0, // Cost tracked by Portkey, not locally
        );

        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: $aiUsage,
            provider: 'portkey',
            model: $data['model'] ?? $request->model,
            latencyMs: $latencyMs,
        );
    }
}
