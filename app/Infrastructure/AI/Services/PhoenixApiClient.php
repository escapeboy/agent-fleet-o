<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Thin GraphQL client for the Phoenix sidecar.
 *
 * Posts queries to `{PHOENIX_OTLP_ENDPOINT}/graphql` with optional Bearer auth.
 * Returns the parsed `data` payload, or `null` on transport/HTTP errors. MCP
 * tools wrap this client and shape the result for their own schemas.
 *
 * Bound as a singleton — the Phoenix endpoint is fixed at boot via env.
 */
class PhoenixApiClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    public function isConfigured(): bool
    {
        return (string) config('llmops.phoenix.endpoint', '') !== '';
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>|null Phoenix's `data` block, or null on failure
     */
    public function query(string $graphql, array $variables = []): ?array
    {
        $endpoint = (string) config('llmops.phoenix.endpoint', '');
        if ($endpoint === '') {
            return null;
        }

        $url = rtrim($endpoint, '/').'/graphql';
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $allowHttp = (bool) config('llmops.phoenix.allow_http', false);

        if ($scheme !== 'https' && ! $allowHttp) {
            return null;
        }

        if ($scheme === 'https') {
            try {
                $this->ssrfGuard->assertPublicUrl($url);
            } catch (\Throwable $e) {
                Log::warning('PhoenixApiClient: SSRF guard blocked', ['url' => $url, 'error' => $e->getMessage()]);

                return null;
            }
        }

        $request = $this->http->timeout(10)->asJson();
        $apiKey = (string) config('llmops.phoenix.api_key', '');
        if ($apiKey !== '') {
            $request = $request->withHeaders(['Authorization' => 'Bearer '.$apiKey]);
        }

        try {
            $response = $request->post($url, [
                'query' => $graphql,
                'variables' => (object) $variables,
            ]);

            if (! $response->successful()) {
                Log::warning('PhoenixApiClient: non-2xx', ['status' => $response->status(), 'url' => $url]);

                return null;
            }

            $body = $response->json();
            if (! is_array($body)) {
                return null;
            }

            if (! empty($body['errors'])) {
                Log::warning('PhoenixApiClient: graphql errors', ['errors' => $body['errors']]);

                return null;
            }

            $data = $body['data'] ?? null;

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::warning('PhoenixApiClient: request failed', ['error' => $e->getMessage(), 'url' => $url]);

            return null;
        }
    }
}
