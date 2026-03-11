<?php

namespace App\Domain\Integration\Drivers\Typeform;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Typeform integration driver.
 *
 * Receives form submission events via Typeform webhooks.
 * Webhook signature: typeform-signature header — sha256= + base64(HMAC-SHA256).
 */
class TypeformIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.typeform.com';

    public function key(): string
    {
        return 'typeform';
    }

    public function label(): string
    {
        return 'Typeform';
    }

    public function description(): string
    {
        return 'Receive Typeform responses as signals to trigger agent workflows on form submissions.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'token' => ['type' => 'password', 'required' => true, 'label' => 'Personal Access Token',
                'hint' => 'Typeform → Account → Settings → Personal tokens'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            return Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/me')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('token');

        if (! $token) {
            return HealthResult::fail('Personal access token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/me');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('description') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition(
                'form_response_submitted',
                'Form Response Submitted',
                'A respondent has completed and submitted a Typeform form.'
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
     * Typeform signature: typeform-signature header — sha256= + base64(HMAC-SHA256(rawBody, secret)).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sig = $headers['typeform-signature'] ?? '';

        if ($sig === '') {
            return false;
        }

        $expected = 'sha256='.base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $formId = $payload['form_response']['form_id']
            ?? $payload['event_id']
            ?? uniqid('typeform_', true);

        $responseId = $payload['form_response']['token']
            ?? $payload['event_id']
            ?? uniqid('typeform_', true);

        return [
            [
                'source_type' => 'typeform',
                'source_id' => 'typeform:'.$responseId,
                'payload' => $payload,
                'tags' => ['typeform', 'form_response', 'form:'.$formId],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        throw new \InvalidArgumentException("Unknown action: {$action}");
    }
}
