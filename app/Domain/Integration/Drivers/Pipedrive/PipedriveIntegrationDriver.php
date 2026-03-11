<?php

namespace App\Domain\Integration\Drivers\Pipedrive;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Pipedrive integration driver.
 *
 * CRM platform. Webhooks have no built-in HMAC signature — uses permissive verification.
 * Supports polling for recent deals and activities.
 */
class PipedriveIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.pipedrive.com/v1';

    public function key(): string
    {
        return 'pipedrive';
    }

    public function label(): string
    {
        return 'Pipedrive';
    }

    public function description(): string
    {
        return 'Track deals, manage contacts, and trigger agent workflows from Pipedrive CRM events.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_token' => ['type' => 'password', 'required' => true, 'label' => 'API Token',
                'hint' => 'Pipedrive → Settings → Personal Preferences → API'],
        ];
    }

    private function token(Integration|array $source): string
    {
        return $source instanceof Integration
            ? ($source->getCredentialSecret('api_token') ?? '')
            : ($source['api_token'] ?? '');
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['api_token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            return Http::timeout(10)
                ->get(self::API_BASE.'/users/me', ['api_token' => $token])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $this->token($integration);

        if (! $token) {
            return HealthResult::fail('API token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::timeout(10)
                ->get(self::API_BASE.'/users/me', ['api_token' => $token]);
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('error') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('deal_created', 'Deal Created', 'A new deal was added to Pipedrive.'),
            new TriggerDefinition('deal_updated', 'Deal Updated', 'A deal was updated.'),
            new TriggerDefinition('person_created', 'Contact Created', 'A new contact was added.'),
            new TriggerDefinition('activity_added', 'Activity Added', 'An activity was scheduled.'),
            new TriggerDefinition('lead_created', 'Lead Created', 'A new lead was added.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_deal', 'Create Deal', 'Create a new deal in Pipedrive.', [
                'title' => ['type' => 'string', 'required' => true, 'label' => 'Deal title'],
                'value' => ['type' => 'string', 'required' => false, 'label' => 'Deal value'],
                'person_id' => ['type' => 'string', 'required' => false, 'label' => 'Associated person ID'],
            ]),
            new ActionDefinition('update_deal', 'Update Deal', 'Update a deal stage or value.', [
                'deal_id' => ['type' => 'string', 'required' => true, 'label' => 'Deal ID'],
                'stage_id' => ['type' => 'string', 'required' => false, 'label' => 'Stage ID'],
                'status' => ['type' => 'string', 'required' => false, 'label' => 'Status: open|won|lost'],
                'note' => ['type' => 'string', 'required' => false, 'label' => 'Deal note'],
            ]),
            new ActionDefinition('create_person', 'Create Contact', 'Create a new contact in Pipedrive.', [
                'name' => ['type' => 'string', 'required' => true, 'label' => 'Full name'],
                'email' => ['type' => 'string', 'required' => false, 'label' => 'Email address'],
                'phone' => ['type' => 'string', 'required' => false, 'label' => 'Phone number'],
            ]),
            new ActionDefinition('add_activity_note', 'Add Activity Note', 'Add a note to a deal.', [
                'deal_id' => ['type' => 'string', 'required' => true, 'label' => 'Deal ID'],
                'content' => ['type' => 'string', 'required' => true, 'label' => 'Note content'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        $token = $this->token($integration);

        if (! $token) {
            return [];
        }

        try {
            $response = Http::timeout(15)
                ->get(self::API_BASE.'/deals', [
                    'api_token' => $token,
                    'sort' => 'update_time DESC',
                    'limit' => 25,
                ]);

            if (! $response->successful()) {
                return [];
            }

            return array_map(fn ($deal) => [
                'source_type' => 'pipedrive',
                'source_id' => 'pipedrive:deal:'.$deal['id'],
                'payload' => $deal,
                'tags' => ['pipedrive', 'deal', $deal['status'] ?? 'open'],
            ], $response->json('data') ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * Pipedrive webhooks have no built-in HMAC. Returns true (permissive).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return true;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $object = $payload['meta']['object'] ?? 'deal';
        $action = $payload['meta']['action'] ?? 'added';
        $id = $payload['current']['id'] ?? $payload['meta']['id'] ?? uniqid('pd_', true);

        $trigger = match ("{$object}_{$action}") {
            'deal_added' => 'deal_created',
            'deal_updated', 'deal_merged' => 'deal_updated',
            'person_added' => 'person_created',
            'activity_added' => 'activity_added',
            'lead_added' => 'lead_created',
            default => "{$object}_{$action}",
        };

        return [
            [
                'source_type' => 'pipedrive',
                'source_id' => "pipedrive:{$object}:{$id}",
                'payload' => $payload,
                'tags' => ['pipedrive', $trigger, $object],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $this->token($integration);
        abort_unless($token, 422, 'Pipedrive API token not configured.');

        return match ($action) {
            'create_deal' => Http::timeout(15)
                ->post(self::API_BASE.'/deals', array_merge(
                    array_filter(['value' => $params['value'] ?? null, 'person_id' => $params['person_id'] ?? null]),
                    ['title' => $params['title'], 'api_token' => $token],
                ))->json(),

            'update_deal' => Http::timeout(15)
                ->put(self::API_BASE."/deals/{$params['deal_id']}", array_filter([
                    'api_token' => $token,
                    'stage_id' => $params['stage_id'] ?? null,
                    'status' => $params['status'] ?? null,
                ]))->json(),

            'create_person' => Http::timeout(15)
                ->post(self::API_BASE.'/persons', array_filter([
                    'api_token' => $token,
                    'name' => $params['name'],
                    'email' => $params['email'] ?? null,
                    'phone' => $params['phone'] ?? null,
                ]))->json(),

            'add_activity_note' => Http::timeout(15)
                ->post(self::API_BASE.'/notes', [
                    'api_token' => $token,
                    'deal_id' => $params['deal_id'],
                    'content' => $params['content'],
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
