<?php

namespace App\Domain\Integration\Drivers\Stripe;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class StripeIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.stripe.com/v1';

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function description(): string
    {
        return 'Receive Stripe events (payments, subscriptions, invoices) and execute actions like creating payment links.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'secret_key' => ['type' => 'string', 'required' => true, 'label' => 'Secret Key', 'hint' => 'dashboard.stripe.com → Developers → API keys'],
            'webhook_secret' => ['type' => 'string', 'required' => false, 'label' => 'Webhook Signing Secret (optional)'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $key = $credentials['secret_key'] ?? null;

        if (! $key) {
            return false;
        }

        try {
            $response = Http::withBasicAuth($key, '')->timeout(10)->get(self::API_BASE.'/balance');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $key = $integration->getCredentialSecret('secret_key');

        if (! $key) {
            return HealthResult::fail('No secret key configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withBasicAuth($key, '')->timeout(10)->get(self::API_BASE.'/balance');
            $latency = (int) ((microtime(true) - $start) * 1000);

            return $response->successful()
                ? HealthResult::ok($latency)
                : HealthResult::fail("HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('payment_intent.succeeded', 'Payment Succeeded', 'A payment intent was successfully confirmed.'),
            new TriggerDefinition('subscription.created', 'Subscription Created', 'A new subscription was created.'),
            new TriggerDefinition('invoice.paid', 'Invoice Paid', 'An invoice was paid.'),
            new TriggerDefinition('customer.subscription.deleted', 'Subscription Cancelled', 'A subscription was cancelled.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('retrieve_customer', 'Retrieve Customer', 'Retrieve a Stripe customer by ID.', [
                'customer_id' => ['type' => 'string', 'required' => true],
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

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sigHeader = $headers['stripe-signature'] ?? '';

        if (! $sigHeader) {
            return false;
        }

        // Parse Stripe signature header: t=timestamp,v1=signature
        $parts = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $receivedSig = $parts['v1'] ?? '';

        if (! $timestamp || ! $receivedSig) {
            return false;
        }

        $tolerance = (int) config('integrations.webhook.timestamp_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        return hash_equals($expected, $receivedSig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $eventType = $payload['type'] ?? 'unknown';
        $eventId = $payload['id'] ?? uniqid('stripe_', true);

        return [
            [
                'source_type' => 'stripe',
                'source_id' => $eventId,
                'payload' => $payload,
                'tags' => ['stripe', $eventType],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $key = $integration->getCredentialSecret('secret_key');

        return match ($action) {
            'retrieve_customer' => Http::withBasicAuth((string) $key, '')
                ->get(self::API_BASE."/customers/{$params['customer_id']}")
                ->json(),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
