<?php

namespace App\Domain\Integration\Drivers\Webclaw;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class WebclawIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'webclaw';
    }

    public function label(): string
    {
        return 'Webclaw';
    }

    public function description(): string
    {
        return 'Web scraping API for LLMs and AI agents. Enables antibot bypass, JS rendering, and structured extraction.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_key' => [
                'type' => 'password',
                'required' => true,
                'label' => 'API Key',
                'hint' => 'Get your API key at webclaw.io/dashboard',
                'placeholder' => 'wc_...',
            ],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $apiKey = $credentials['api_key'] ?? null;

        if (! $apiKey) {
            return false;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->post('https://api.webclaw.io/v1/scrape', [
                    'url' => 'https://example.com',
                    'format' => 'text',
                ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $apiKey = $integration->getCredentialSecret('api_key');

        if (! $apiKey) {
            return HealthResult::fail('Webclaw API key not configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->post('https://api.webclaw.io/v1/scrape', [
                    'url' => 'https://example.com',
                    'format' => 'text',
                ]);

            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency, 'Connected to Webclaw API');
            }

            return HealthResult::fail("Webclaw returned HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail('Could not reach Webclaw API: '.$e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('scrape', 'Scrape URL', 'Scrape a URL and return its content.', [
                'url' => ['type' => 'string', 'required' => true, 'label' => 'URL to scrape'],
                'format' => ['type' => 'string', 'required' => false, 'label' => 'Output format (markdown, text, html)'],
            ]),
            new ActionDefinition('crawl', 'Crawl Website', 'Crawl a website and return all page content.', [
                'url' => ['type' => 'string', 'required' => true, 'label' => 'Starting URL'],
                'max_pages' => ['type' => 'number', 'required' => false, 'label' => 'Maximum pages to crawl'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0;
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return false;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $apiKey = $integration->getCredentialSecret('api_key');
        abort_unless($apiKey, 422, 'Webclaw API key not configured.');

        $http = Http::withToken($apiKey)->timeout(30);
        $baseUrl = config('services.webclaw.cloud_url', 'https://api.webclaw.io');

        if ($action === 'scrape') {
            $response = $http->post($baseUrl.'/v1/scrape', [
                'url' => $params['url'],
                'format' => $params['format'] ?? 'markdown',
            ]);

            abort_unless($response->successful(), 502, 'Webclaw scrape failed.');

            return $response->json();
        }

        if ($action === 'crawl') {
            $response = $http->timeout(120)->post($baseUrl.'/v1/crawl', [
                'url' => $params['url'],
                'max_pages' => $params['max_pages'] ?? 30,
                'format' => 'markdown',
            ]);

            abort_unless($response->successful(), 502, 'Webclaw crawl failed.');

            return $response->json();
        }

        throw new \InvalidArgumentException("Unknown action: {$action}");
    }
}
