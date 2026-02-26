<?php

namespace App\Domain\Integration\Drivers\Generic;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Generic driver for polling any HTTP/JSON endpoint on a schedule.
 *
 * Integration.config fields:
 *   - url: string         — Endpoint to poll
 *   - headers: array      — Extra HTTP headers (e.g. Authorization)
 *   - result_path: string — Dot-notation path to array of items (e.g. 'data.items')
 *   - cursor_field: string — Field to track the latest seen record (e.g. 'id', 'updated_at')
 *   - method: string      — HTTP method (default: GET)
 */
class ApiPollingDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'api_polling';
    }

    public function label(): string
    {
        return 'API Polling';
    }

    public function description(): string
    {
        return 'Poll any HTTP/JSON endpoint on a configurable schedule and ingest results as signals.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_key' => ['type' => 'string', 'required' => false, 'label' => 'API Key (optional)'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        return true;
    }

    public function ping(Integration $integration): HealthResult
    {
        /** @var array<string, mixed> $config */
        $config = $integration->config ?? [];
        $url = $config['url'] ?? null;

        if (! $url) {
            return HealthResult::fail('No URL configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withHeaders($this->resolveHeaders($integration, $config))
                ->timeout(10)
                ->get($url);

            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail("HTTP {$response->status()}: {$response->body()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition(
                key: 'new_record',
                label: 'New Record',
                description: 'Fires when a new item is returned from the polled endpoint.',
                outputSchema: ['item' => ['type' => 'object']],
            ),
        ];
    }

    public function actions(): array
    {
        return [];
    }

    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        /** @var array<string, mixed> $config */
        $config = $integration->config ?? [];
        $url = $config['url'] ?? null;

        if (! $url) {
            return [];
        }

        try {
            $response = Http::withHeaders($this->resolveHeaders($integration, $config))
                ->timeout(30)
                ->get($url);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $resultPath = $config['result_path'] ?? null;

            if ($resultPath) {
                $items = data_get($data, $resultPath, []);
            } else {
                $items = is_array($data) ? $data : [$data];
            }

            if (! is_array($items)) {
                return [];
            }

            $cursorField = $config['cursor_field'] ?? null;
            $lastCursor = $config['last_cursor'] ?? null;

            $signals = [];
            $newCursor = $lastCursor;

            foreach ($items as $item) {
                if ($cursorField && $lastCursor !== null) {
                    $value = data_get($item, $cursorField);
                    if ($value <= $lastCursor) {
                        continue;
                    }

                    if ($newCursor === null || $value > $newCursor) {
                        $newCursor = $value;
                    }
                }

                $signals[] = [
                    'source_type' => 'api_polling',
                    'source_id' => (string) ($item['id'] ?? uniqid('poll_', true)),
                    'payload' => $item,
                ];
            }

            if ($cursorField && $newCursor !== $lastCursor) {
                $config['last_cursor'] = $newCursor;
                $integration->update(['config' => $config]);
            }

            return $signals;
        } catch (\Throwable) {
            return [];
        }
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
        throw new \RuntimeException('API Polling driver does not support outbound actions.');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function resolveHeaders(Integration $integration, array $config): array
    {
        /** @var array<string, string> $headers */
        $headers = $config['headers'] ?? [];
        $apiKey = $integration->getCredentialSecret('api_key');

        if ($apiKey) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        return $headers;
    }
}
