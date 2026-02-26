<?php

namespace App\Domain\Integration\Drivers\Generic;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;

/**
 * Generic driver for receiving inbound webhooks.
 *
 * No polling, no API key — only an HMAC-signed webhook endpoint.
 * The signing secret is stored in WebhookRoute.signing_secret.
 */
class WebhookOnlyDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Webhook (Incoming)';
    }

    public function description(): string
    {
        return 'Receive inbound webhooks from any source and convert them into signals.';
    }

    public function authType(): AuthType
    {
        return AuthType::WebhookOnly;
    }

    public function credentialSchema(): array
    {
        return [];
    }

    public function validateCredentials(array $credentials): bool
    {
        return true;
    }

    public function ping(Integration $integration): HealthResult
    {
        return HealthResult::ok();
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition(
                key: 'webhook_received',
                label: 'Webhook Received',
                description: 'Fires when any webhook payload arrives at this endpoint.',
                outputSchema: ['payload' => ['type' => 'object'], 'headers' => ['type' => 'object']],
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

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $signature = $headers['x-webhook-signature'] ?? $headers['X-Webhook-Signature'] ?? '';

        if (! $signature) {
            return true;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [
            [
                'source_type' => 'webhook',
                'source_id' => $headers['x-delivery-id'] ?? $headers['X-Delivery-Id'] ?? uniqid('wh_', true),
                'payload' => $payload,
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        throw new \RuntimeException('Webhook-only driver does not support outbound actions.');
    }
}
