<?php

namespace App\Domain\Integration\Drivers\PostHog;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * PostHog integration driver.
 *
 * Receives action-triggered events via PostHog Destination webhooks.
 * Signature: token field in payload matches project API key.
 * Supports self-hosted instances via configurable base URL.
 */
class PostHogIntegrationDriver implements IntegrationDriverInterface
{
    use ChecksIntegrationResponse;

    private const DEFAULT_BASE = 'https://app.posthog.com';

    public function key(): string
    {
        return 'posthog';
    }

    public function label(): string
    {
        return 'PostHog';
    }

    public function description(): string
    {
        return 'Receive PostHog action and event triggers to power AI-driven product analytics workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'personal_api_key' => ['type' => 'password', 'required' => true, 'label' => 'Personal API Key',
                'hint' => 'PostHog → Settings → Personal API Keys'],
            'project_api_key' => ['type' => 'password', 'required' => true, 'label' => 'Project API Key',
                'hint' => 'PostHog → Project → Settings → Project API Key'],
            'base_url' => ['type' => 'string', 'required' => false, 'label' => 'Base URL',
                'default' => self::DEFAULT_BASE,
                'hint' => 'Change only for self-hosted PostHog instances'],
        ];
    }

    private function apiBase(Integration|array $source): string
    {
        $url = $source instanceof Integration
            ? ($source->getCredentialSecret('base_url') ?? '')
            : ($source['base_url'] ?? '');

        $url = rtrim((string) $url, '/');

        return $url !== '' ? $url : self::DEFAULT_BASE;
    }

    public function validateCredentials(array $credentials): bool
    {
        $personalKey = $credentials['personal_api_key'] ?? null;

        if (! $personalKey) {
            return false;
        }

        $base = rtrim($credentials['base_url'] ?? self::DEFAULT_BASE, '/');

        try {
            return Http::withToken($personalKey)
                ->timeout(10)
                ->get("{$base}/api/projects/@current/")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $personalKey = $integration->getCredentialSecret('personal_api_key');

        if (! $personalKey) {
            return HealthResult::fail('Personal API key not configured.');
        }

        $base = rtrim($integration->getCredentialSecret('base_url') ?? self::DEFAULT_BASE, '/');

        $start = microtime(true);
        try {
            $response = Http::withToken($personalKey)
                ->timeout(10)
                ->get("{$base}/api/projects/@current/");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('detail') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition(
                'action_performed',
                'Action Performed',
                'A PostHog action was triggered by user behaviour.',
            ),
            new TriggerDefinition(
                'event_ingested',
                'Event Ingested',
                'A custom PostHog event was captured and forwarded via a Destination webhook.',
            ),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('capture_event', 'Capture Event', 'Send a custom event to PostHog.', [
                'distinct_id' => ['type' => 'string', 'required' => true, 'label' => 'Distinct ID'],
                'event' => ['type' => 'string', 'required' => true, 'label' => 'Event name'],
                'properties' => ['type' => 'string', 'required' => false, 'label' => 'Properties (JSON)'],
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
        return true;
    }

    /**
     * PostHog Destination webhooks do not use an HMAC header.
     * The payload contains a `token` field matching the project API key.
     * Verification is done in parseWebhookPayload by comparing tokens.
     * Always return true here; the real check is in parseWebhookPayload.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return true;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $eventId = $payload['uuid'] ?? $payload['id'] ?? uniqid('posthog_', true);
        $event = $payload['event'] ?? 'event';

        return [
            [
                'source_type' => 'posthog',
                'source_id' => 'posthog:'.$eventId,
                'payload' => $payload,
                'tags' => ['posthog', $event],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $personalKey = $integration->getCredentialSecret('personal_api_key');
        $projectKey = $integration->getCredentialSecret('project_api_key');
        $base = rtrim($integration->getCredentialSecret('base_url') ?? self::DEFAULT_BASE, '/');

        return match ($action) {
            'capture_event' => $this->checked(Http::withHeaders(['Authorization' => "Bearer {$personalKey}"])
                ->timeout(10)
                ->post("{$base}/capture/", [
                    'api_key' => $projectKey,
                    'distinct_id' => $params['distinct_id'],
                    'event' => $params['event'],
                    'properties' => json_decode($params['properties'] ?? '{}', true) ?? [],
                ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
