<?php

namespace App\Domain\Integration\Drivers\Segment;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Segment integration driver.
 *
 * Receives customer data events forwarded from Segment Source Functions or Destinations.
 * Signature: x-signature header — raw hex HMAC-SHA1.
 */
class SegmentIntegrationDriver implements IntegrationDriverInterface
{
    use ChecksIntegrationResponse;

    private const TRACK_URL = 'https://api.segment.io/v1/track';

    private const IDENTIFY_URL = 'https://api.segment.io/v1/identify';

    public function key(): string
    {
        return 'segment';
    }

    public function label(): string
    {
        return 'Segment';
    }

    public function description(): string
    {
        return 'Receive Segment track, identify, and page events to trigger AI agent workflows from customer data.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'write_key' => ['type' => 'password', 'required' => true, 'label' => 'Write Key',
                'hint' => 'Segment → Sources → your source → Settings → API Keys → Write Key'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $writeKey = $credentials['write_key'] ?? null;

        if (! $writeKey) {
            return false;
        }

        try {
            // Send a test identify call — Segment returns 200 on valid keys
            $response = Http::withBasicAuth($writeKey, '')
                ->timeout(10)
                ->post(self::IDENTIFY_URL, [
                    'userId' => 'test-validation',
                    'traits' => ['source' => 'fleetq-integration-validation'],
                ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $writeKey = $integration->getCredentialSecret('write_key');

        if (! $writeKey) {
            return HealthResult::fail('Write key not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withBasicAuth($writeKey, '')
                ->timeout(10)
                ->post(self::TRACK_URL, [
                    'userId' => 'fleetq-ping',
                    'event' => 'FleetQ Integration Ping',
                ]);
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail('Authentication failed — check write key');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('track', 'Track Event', 'A Segment track() call was forwarded.'),
            new TriggerDefinition('identify', 'Identify Event', 'A Segment identify() call was forwarded.'),
            new TriggerDefinition('page', 'Page Event', 'A Segment page() call was forwarded.'),
            new TriggerDefinition('group', 'Group Event', 'A Segment group() call was forwarded.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('track_event', 'Track Event', 'Send a track event to Segment.', [
                'user_id' => ['type' => 'string', 'required' => true, 'label' => 'User ID'],
                'event' => ['type' => 'string', 'required' => true, 'label' => 'Event name'],
                'properties' => ['type' => 'string', 'required' => false, 'label' => 'Properties (JSON)'],
            ]),
            new ActionDefinition('identify_user', 'Identify User', 'Send an identify call to Segment.', [
                'user_id' => ['type' => 'string', 'required' => true, 'label' => 'User ID'],
                'traits' => ['type' => 'string', 'required' => false, 'label' => 'Traits (JSON)'],
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
     * Segment signature: x-signature header — raw hex HMAC-SHA1 of rawBody.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sig = $headers['x-signature'] ?? '';

        if ($sig === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha1', $rawBody, $secret), $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $messageId = $payload['messageId'] ?? $payload['message_id'] ?? uniqid('segment_', true);
        $type = strtolower($payload['type'] ?? 'track');
        $event = $payload['event'] ?? $type;

        return [
            [
                'source_type' => 'segment',
                'source_id' => 'segment:'.$messageId,
                'payload' => $payload,
                'tags' => ['segment', $type, $event],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $writeKey = $integration->getCredentialSecret('write_key');

        abort_unless($writeKey, 422, 'Segment write key not configured.');

        return match ($action) {
            'track_event' => $this->checked(Http::withBasicAuth($writeKey, '')
                ->timeout(10)
                ->post(self::TRACK_URL, [
                    'userId' => $params['user_id'],
                    'event' => $params['event'],
                    'properties' => json_decode($params['properties'] ?? '{}', true) ?? [],
                ]))->json(),

            'identify_user' => $this->checked(Http::withBasicAuth($writeKey, '')
                ->timeout(10)
                ->post(self::IDENTIFY_URL, [
                    'userId' => $params['user_id'],
                    'traits' => json_decode($params['traits'] ?? '{}', true) ?? [],
                ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
