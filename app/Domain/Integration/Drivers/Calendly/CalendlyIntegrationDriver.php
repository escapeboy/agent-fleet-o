<?php

namespace App\Domain\Integration\Drivers\Calendly;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Calendly integration driver.
 *
 * Receives booking and cancellation events via Calendly webhooks.
 * Signature: calendly-webhook-signature header — t={timestamp},v1={hmac_hex}
 * HMAC-SHA256 over "{timestamp}.{rawBody}".
 */
class CalendlyIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.calendly.com';

    public function key(): string
    {
        return 'calendly';
    }

    public function label(): string
    {
        return 'Calendly';
    }

    public function description(): string
    {
        return 'Receive booking and cancellation events from Calendly to trigger agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'password', 'required' => true, 'label' => 'Personal Access Token',
                'hint' => 'Calendly → Account → Integrations → API & Webhooks'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            return Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/users/me')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token');

        if (! $token) {
            return HealthResult::fail('Personal access token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition(
                'invitee_created',
                'Booking Made',
                'An invitee has scheduled a meeting via Calendly.',
            ),
            new TriggerDefinition(
                'invitee_canceled',
                'Booking Canceled',
                'An invitee has canceled a scheduled Calendly meeting.',
            ),
        ];
    }

    public function actions(): array
    {
        return [];
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
     * Calendly signature: calendly-webhook-signature header — t={timestamp},v1={hmac_hex}
     * HMAC is SHA256 over "{timestamp}.{rawBody}".
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $header = $headers['calendly-webhook-signature'] ?? '';

        if ($header === '') {
            return false;
        }

        if (! preg_match('/t=(\d+),v1=([a-f0-9]+)/', $header, $matches)) {
            return false;
        }

        $expected = hash_hmac('sha256', $matches[1].'.'.$rawBody, $secret);

        return hash_equals($expected, $matches[2]);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $event = $payload['event'] ?? 'invitee.created';
        $uri = $payload['payload']['uri'] ?? uniqid('calendly_', true);
        $sourceId = basename($uri);

        $trigger = match ($event) {
            'invitee.created' => 'invitee_created',
            'invitee.canceled' => 'invitee_canceled',
            default => str_replace('.', '_', $event),
        };

        return [
            [
                'source_type' => 'calendly',
                'source_id' => 'calendly:'.$sourceId,
                'payload' => $payload,
                'tags' => ['calendly', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        throw new \InvalidArgumentException("Unknown action: {$action}");
    }
}
