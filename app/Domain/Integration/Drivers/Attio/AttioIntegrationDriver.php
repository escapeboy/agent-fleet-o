<?php

namespace App\Domain\Integration\Drivers\Attio;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Attio integration driver.
 *
 * Modern CRM with webhook support and a clean REST API.
 * Signature: x-attio-signature header — raw hex HMAC-SHA256.
 */
class AttioIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.attio.com/v2';

    public function key(): string
    {
        return 'attio';
    }

    public function label(): string
    {
        return 'Attio';
    }

    public function description(): string
    {
        return 'Sync Attio CRM records and receive record creation or update events to trigger agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'password', 'required' => true, 'label' => 'Access Token',
                'hint' => 'Attio → Workspace Settings → API → Create token'],
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
                ->get(self::API_BASE.'/self')
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
                ->timeout(10)
                ->get(self::API_BASE.'/self');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('error.message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('record_created', 'Record Created', 'A new record was created in Attio.'),
            new TriggerDefinition('record_updated', 'Record Updated', 'An existing record was updated in Attio.'),
            new TriggerDefinition('note_created', 'Note Created', 'A note was added to a record in Attio.'),
            new TriggerDefinition('task_created', 'Task Created', 'A task was created in Attio.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_record', 'Create Record', 'Create a new record in an Attio list.', [
                'object_slug' => ['type' => 'string', 'required' => true, 'label' => 'Object type (e.g. people, companies)'],
                'values' => ['type' => 'string', 'required' => true, 'label' => 'Attribute values (JSON)'],
            ]),
            new ActionDefinition('update_record', 'Update Record', 'Update an existing Attio record.', [
                'object_slug' => ['type' => 'string', 'required' => true, 'label' => 'Object type'],
                'record_id' => ['type' => 'string', 'required' => true, 'label' => 'Record ID'],
                'values' => ['type' => 'string', 'required' => true, 'label' => 'Attribute values (JSON)'],
            ]),
            new ActionDefinition('add_note', 'Add Note', 'Add a note to an Attio record.', [
                'parent_object' => ['type' => 'string', 'required' => true, 'label' => 'Parent object type'],
                'parent_record_id' => ['type' => 'string', 'required' => true, 'label' => 'Parent record ID'],
                'title' => ['type' => 'string', 'required' => false, 'label' => 'Note title'],
                'content' => ['type' => 'string', 'required' => true, 'label' => 'Note content'],
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
     * Attio signature: x-attio-signature header — raw hex HMAC-SHA256.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sig = $headers['x-attio-signature'] ?? '';

        if ($sig === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $eventType = $payload['event_type'] ?? 'record.created';
        $eventId = $payload['id']['event_id'] ?? uniqid('attio_', true);
        $objectType = $payload['data']['record']['object_slug'] ?? 'record';

        $trigger = match ($eventType) {
            'record-created' => 'record_created',
            'record-updated' => 'record_updated',
            'note-created' => 'note_created',
            'task-created' => 'task_created',
            default => str_replace('-', '_', $eventType),
        };

        return [
            [
                'source_type' => 'attio',
                'source_id' => 'attio:'.$eventId,
                'payload' => $payload,
                'tags' => ['attio', $trigger, $objectType],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token');

        abort_unless($token, 422, 'Attio access token not configured.');

        return match ($action) {
            'create_record' => Http::withToken($token)->timeout(15)
                ->post(self::API_BASE."/objects/{$params['object_slug']}/records", [
                    'data' => ['values' => json_decode($params['values'], true) ?? []],
                ])->json(),

            'update_record' => Http::withToken($token)->timeout(15)
                ->patch(self::API_BASE."/objects/{$params['object_slug']}/records/{$params['record_id']}", [
                    'data' => ['values' => json_decode($params['values'], true) ?? []],
                ])->json(),

            'add_note' => Http::withToken($token)->timeout(15)
                ->post(self::API_BASE.'/notes', [
                    'data' => [
                        'parent_object' => $params['parent_object'],
                        'parent_record_id' => $params['parent_record_id'],
                        'title' => $params['title'] ?? null,
                        'content' => ['document' => ['content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $params['content']]]],
                        ]]],
                    ],
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
