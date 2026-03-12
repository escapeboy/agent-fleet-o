<?php

namespace App\Domain\Integration\Drivers\Intercom;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Intercom integration driver.
 *
 * In-app messaging and customer support platform.
 * Signature: x-hub-signature header — sha1=HMAC-SHA1(rawBody, secret).
 * Also checks x-hub-timestamp for replay protection (5-minute window).
 */
class IntercomIntegrationDriver implements IntegrationDriverInterface
{
    use ChecksIntegrationResponse;

    private const API_BASE = 'https://api.intercom.io';

    public function key(): string
    {
        return 'intercom';
    }

    public function label(): string
    {
        return 'Intercom';
    }

    public function description(): string
    {
        return 'Receive Intercom conversation and contact events to power AI-driven customer support automation.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'password', 'required' => true, 'label' => 'Access Token',
                'hint' => 'Settings → Integrations → Developer Hub → your app → Authentication → Access Token'],
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
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(10)
                ->get(self::API_BASE.'/me')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token');

        if (! $token) {
            return HealthResult::fail('Access token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(10)
                ->get(self::API_BASE.'/me');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('errors.0.message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('conversation_created', 'Conversation Created', 'A new Intercom conversation was started.'),
            new TriggerDefinition('conversation_part_created', 'Reply Created', 'A reply was added to an Intercom conversation.'),
            new TriggerDefinition('contact_created', 'Contact Created', 'A new contact was created in Intercom.'),
            new TriggerDefinition('event_created', 'Event Tracked', 'A custom event was tracked for a contact.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('reply_to_conversation', 'Reply to Conversation', 'Send a reply in an Intercom conversation.', [
                'conversation_id' => ['type' => 'string', 'required' => true, 'label' => 'Conversation ID'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Reply body'],
                'message_type' => ['type' => 'string', 'required' => false, 'label' => 'Type: comment or note (default: comment)'],
            ]),
            new ActionDefinition('create_note', 'Create Note', 'Add a private note to an Intercom conversation.', [
                'conversation_id' => ['type' => 'string', 'required' => true, 'label' => 'Conversation ID'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Note body'],
            ]),
            new ActionDefinition('update_contact', 'Update Contact', 'Update attributes of an Intercom contact.', [
                'contact_id' => ['type' => 'string', 'required' => true, 'label' => 'Contact ID'],
                'name' => ['type' => 'string', 'required' => false, 'label' => 'Full name'],
                'email' => ['type' => 'string', 'required' => false, 'label' => 'Email'],
                'custom_attributes' => ['type' => 'string', 'required' => false, 'label' => 'Custom attributes (JSON)'],
            ]),
            new ActionDefinition('tag_contact', 'Tag Contact', 'Tag a contact in Intercom.', [
                'contact_id' => ['type' => 'string', 'required' => true, 'label' => 'Contact ID'],
                'tag_name' => ['type' => 'string', 'required' => true, 'label' => 'Tag name'],
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
     * Intercom signature: x-hub-signature header — sha1=HMAC-SHA1(rawBody, secret).
     * Also validates x-hub-timestamp for replay protection (5-minute window).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $ts = (int) ($headers['x-hub-timestamp'] ?? 0);
        $sig = $headers['x-hub-signature'] ?? '';

        if ($sig === '') {
            return false;
        }

        if ($ts > 0 && abs(time() - $ts) > 300) {
            return false;
        }

        $expected = 'sha1='.hash_hmac('sha1', $rawBody, $secret);

        return hash_equals($expected, $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $topic = $payload['topic'] ?? 'conversation.created';
        $itemId = $payload['data']['item']['id'] ?? uniqid('intercom_', true);

        $trigger = match ($topic) {
            'conversation.created' => 'conversation_created',
            'conversation_part.created' => 'conversation_part_created',
            'contact.created', 'user.created' => 'contact_created',
            'event.created' => 'event_created',
            default => str_replace('.', '_', $topic),
        };

        return [
            [
                'source_type' => 'intercom',
                'source_id' => 'intercom:'.$itemId,
                'payload' => $payload,
                'tags' => ['intercom', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token');

        abort_unless($token, 422, 'Intercom access token not configured.');

        $http = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(15);

        return match ($action) {
            'reply_to_conversation' => $this->checked($http->post(
                self::API_BASE."/conversations/{$params['conversation_id']}/reply",
                [
                    'message_type' => $params['message_type'] ?? 'comment',
                    'type' => 'admin',
                    'body' => $params['body'],
                ],
            ))->json(),

            'create_note' => $this->checked($http->post(
                self::API_BASE."/conversations/{$params['conversation_id']}/reply",
                ['message_type' => 'note', 'type' => 'admin', 'body' => $params['body']],
            ))->json(),

            'update_contact' => $this->checked($http->put(self::API_BASE."/contacts/{$params['contact_id']}", array_filter([
                'name' => $params['name'] ?? null,
                'email' => $params['email'] ?? null,
                'custom_attributes' => isset($params['custom_attributes'])
                    ? json_decode($params['custom_attributes'], true)
                    : null,
            ])))->json(),

            'tag_contact' => $this->checked($http->post(self::API_BASE.'/tags', [
                'name' => $params['tag_name'],
                'users' => [['id' => $params['contact_id']]],
            ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
