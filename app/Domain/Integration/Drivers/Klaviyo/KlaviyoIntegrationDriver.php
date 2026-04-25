<?php

namespace App\Domain\Integration\Drivers\Klaviyo;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klaviyo email/SMS marketing integration driver.
 *
 * Uses Private API Key with Klaviyo-API-Key header.
 * All requests require revision header: 2024-02-15.
 * Webhook signature: X-Klaviyo-Signature = HMAC SHA256(secret, timestamp + body).
 */
class KlaviyoIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://a.klaviyo.com/api';

    private const REVISION = '2024-02-15';

    public function key(): string
    {
        return 'klaviyo';
    }

    public function label(): string
    {
        return 'Klaviyo';
    }

    public function description(): string
    {
        return 'Manage Klaviyo profiles, track events, trigger flows, and sync subscribers from agent pipelines.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_key' => ['type' => 'password', 'required' => true,  'label' => 'Private API Key',
                'hint' => 'From Klaviyo → Account → Settings → API Keys → Create Private API Key'],
            'webhook_secret' => ['type' => 'password', 'required' => false, 'label' => 'Webhook Signing Secret',
                'hint' => 'From Klaviyo → Integrations → Webhooks → signing secret'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $apiKey = $credentials['api_key'] ?? null;
        if (! $apiKey) {
            return false;
        }

        try {
            $response = $this->request($apiKey)->get(self::API_BASE.'/accounts/');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $apiKey = $integration->getCredentialSecret('api_key');
        if (! $apiKey) {
            return HealthResult::fail('No API key configured.');
        }

        $start = microtime(true);
        try {
            $response = $this->request($apiKey)->get(self::API_BASE.'/accounts/');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $name = $response->json('data.0.attributes.contact_information.organization_name', 'Klaviyo');

                return HealthResult::ok(
                    latencyMs: $latency,
                    message: "Connected as {$name}",
                    identity: [
                        'label' => $name,
                        'identifier' => $response->json('data.0.id'),
                        'url' => null,
                        'metadata' => array_filter([
                            'account_id' => $response->json('data.0.id'),
                            'industry' => $response->json('data.0.attributes.industry'),
                        ]),
                    ],
                );
            }

            return HealthResult::fail($response->json('errors.0.detail') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('profile.created', 'Profile Created', 'A new Klaviyo profile was created.'),
            new TriggerDefinition('profile.subscribed', 'Profile Subscribed', 'A profile subscribed to a list.'),
            new TriggerDefinition('profile.unsubscribed', 'Profile Unsubscribed', 'A profile unsubscribed from a list.'),
            new TriggerDefinition('event.created', 'Event Created', 'A custom metric event was recorded (e.g. Placed Order).'),
            new TriggerDefinition('flow.send', 'Flow Message Sent', 'A flow message was sent to a profile.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_profile', 'Create/Update Profile', 'Upsert a Klaviyo profile by email.', [
                'email' => ['type' => 'string', 'required' => true,  'label' => 'Email address'],
                'first_name' => ['type' => 'string', 'required' => false, 'label' => 'First name'],
                'last_name' => ['type' => 'string', 'required' => false, 'label' => 'Last name'],
                'phone' => ['type' => 'string', 'required' => false, 'label' => 'Phone (E.164)'],
                'properties' => ['type' => 'array',  'required' => false, 'label' => 'Custom properties map'],
            ]),
            new ActionDefinition('add_to_list', 'Add to List', 'Subscribe a profile to a Klaviyo list.', [
                'list_id' => ['type' => 'string', 'required' => true, 'label' => 'List ID'],
                'email' => ['type' => 'string', 'required' => true, 'label' => 'Email address'],
            ]),
            new ActionDefinition('remove_from_list', 'Remove from List', 'Unsubscribe a profile from a list.', [
                'list_id' => ['type' => 'string', 'required' => true, 'label' => 'List ID'],
                'email' => ['type' => 'string', 'required' => true, 'label' => 'Email address'],
            ]),
            new ActionDefinition('track_event', 'Track Event', 'Record a custom metric event for a profile.', [
                'email' => ['type' => 'string', 'required' => true,  'label' => 'Profile email'],
                'metric' => ['type' => 'string', 'required' => true,  'label' => 'Metric name (e.g. Placed Order)'],
                'properties' => ['type' => 'array',  'required' => false, 'label' => 'Event properties map'],
            ]),
            new ActionDefinition('update_profile_properties', 'Update Profile Properties', 'Patch custom properties on a profile.', [
                'email' => ['type' => 'string', 'required' => true, 'label' => 'Profile email'],
                'properties' => ['type' => 'array',  'required' => true, 'label' => 'Properties to update'],
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
     * Klaviyo signature: X-Klaviyo-Signature = HMAC SHA256(secret, timestamp + rawBody)
     * Timestamp: X-Klaviyo-Timestamp header (Unix seconds).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $signature = $headers['x-klaviyo-signature'] ?? '';
        $timestamp = $headers['x-klaviyo-timestamp'] ?? '';

        if (! $signature || ! $timestamp) {
            return false;
        }

        // Reject stale requests (>5 minutes)
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.$rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $type = $payload['type'] ?? ($payload['data']['type'] ?? 'unknown');

        return [
            [
                'source_type' => 'klaviyo',
                'source_id' => 'kl:'.($payload['data']['id'] ?? uniqid('kl_', true)),
                'payload' => $payload,
                'tags' => ['klaviyo', str_replace('.', '_', $type)],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $apiKey = $integration->getCredentialSecret('api_key');
        abort_unless($apiKey, 422, 'Klaviyo API key not configured.');

        return match ($action) {
            'create_profile' => $this->request($apiKey)->post(self::API_BASE.'/profile-import/', [
                'data' => [
                    'type' => 'profile',
                    'attributes' => array_filter([
                        'email' => $params['email'],
                        'first_name' => $params['first_name'] ?? null,
                        'last_name' => $params['last_name'] ?? null,
                        'phone' => $params['phone'] ?? null,
                        'properties' => $params['properties'] ?? [],
                    ]),
                ],
            ])->json(),

            'add_to_list' => $this->request($apiKey)->post(self::API_BASE."/lists/{$params['list_id']}/relationships/profiles/", [
                'data' => [[
                    'type' => 'profile',
                    'attributes' => ['email' => $params['email']],
                ]],
            ])->json(),

            'remove_from_list' => $this->request($apiKey)->delete(self::API_BASE."/lists/{$params['list_id']}/relationships/profiles/", [
                'data' => [[
                    'type' => 'profile',
                    'attributes' => ['email' => $params['email']],
                ]],
            ])->json(),

            'track_event' => $this->request($apiKey)->post(self::API_BASE.'/events/', [
                'data' => [
                    'type' => 'event',
                    'attributes' => [
                        'metric' => ['data' => ['type' => 'metric', 'attributes' => ['name' => $params['metric']]]],
                        'profile' => ['data' => ['type' => 'profile', 'attributes' => ['email' => $params['email']]]],
                        'properties' => $params['properties'] ?? [],
                        'time' => now()->toIso8601String(),
                    ],
                ],
            ])->json(),

            'update_profile_properties' => $this->upsertAndPatch($apiKey, $params['email'], $params['properties']),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function request(string $apiKey): PendingRequest
    {
        return Http::withHeaders([
            'Klaviyo-API-Key' => $apiKey,
            'revision' => self::REVISION,
        ])->timeout(15)->acceptJson();
    }

    private function upsertAndPatch(string $apiKey, string $email, array $properties): array
    {
        // Look up profile ID by email first
        $search = $this->request($apiKey)->get(self::API_BASE.'/profiles/', [
            'filter' => "equals(email,\"{$email}\")",
        ]);

        $profileId = $search->json('data.0.id');
        if (! $profileId) {
            return ['error' => 'Profile not found'];
        }

        return $this->request($apiKey)->patch(self::API_BASE."/profiles/{$profileId}/", [
            'data' => [
                'type' => 'profile',
                'id' => $profileId,
                'attributes' => ['properties' => $properties],
            ],
        ])->json();
    }
}
