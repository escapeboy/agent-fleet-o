<?php

namespace App\Domain\Integration\Drivers\Monday;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Monday.com integration driver.
 *
 * Work management platform with a GraphQL API.
 * Signature: authorization header — plain token comparison.
 * Handles Monday's challenge-response handshake in parseWebhookPayload.
 */
class MondayIntegrationDriver implements IntegrationDriverInterface
{
    private const API_URL = 'https://api.monday.com/v2';

    public function key(): string
    {
        return 'monday';
    }

    public function label(): string
    {
        return 'Monday.com';
    }

    public function description(): string
    {
        return 'Receive Monday.com board item events and manage tasks, statuses, and updates from agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_key' => ['type' => 'password', 'required' => true, 'label' => 'API Key (v2 Token)',
                'hint' => 'Profile Photo → Developers → My Access Tokens'],
        ];
    }

    private function graphql(Integration|array $source, string $query, array $variables = []): array
    {
        $token = $source instanceof Integration
            ? $source->getCredentialSecret('api_key')
            : ($source['api_key'] ?? '');

        $response = Http::withToken((string) $token)
            ->withHeaders(['API-Version' => '2024-01'])
            ->timeout(15)
            ->post(self::API_URL, ['query' => $query, 'variables' => $variables]);

        return $response->json() ?? [];
    }

    public function validateCredentials(array $credentials): bool
    {
        if (empty($credentials['api_key'])) {
            return false;
        }

        try {
            $result = $this->graphql($credentials, '{ me { name } }');

            return isset($result['data']['me']['name']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('api_key');

        if (! $token) {
            return HealthResult::fail('API key not configured.');
        }

        $start = microtime(true);
        try {
            $result = $this->graphql($integration, '{ me { name } }');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if (isset($result['data']['me']['name'])) {
                return HealthResult::ok($latency);
            }

            $error = $result['errors'][0]['message'] ?? 'Authentication failed';

            return HealthResult::fail($error);
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('item_created', 'Item Created', 'A new item was created on a Monday.com board.'),
            new TriggerDefinition('item_status_changed', 'Status Changed', 'An item status column was changed.'),
            new TriggerDefinition('column_value_changed', 'Column Value Changed', 'A column value was updated.'),
            new TriggerDefinition('subitem_created', 'Subitem Created', 'A subitem was added to an item.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_item', 'Create Item', 'Create a new item on a Monday.com board.', [
                'board_id' => ['type' => 'string', 'required' => true, 'label' => 'Board ID'],
                'item_name' => ['type' => 'string', 'required' => true, 'label' => 'Item name'],
                'group_id' => ['type' => 'string', 'required' => false, 'label' => 'Group ID'],
            ]),
            new ActionDefinition('update_status', 'Update Status', 'Change the status of a Monday.com item.', [
                'item_id' => ['type' => 'string', 'required' => true, 'label' => 'Item ID'],
                'board_id' => ['type' => 'string', 'required' => true, 'label' => 'Board ID'],
                'column_id' => ['type' => 'string', 'required' => true, 'label' => 'Status column ID'],
                'value' => ['type' => 'string', 'required' => true, 'label' => 'Status label (e.g. Done, In Progress)'],
            ]),
            new ActionDefinition('create_update', 'Post Update', 'Post an update (comment) on a Monday.com item.', [
                'item_id' => ['type' => 'string', 'required' => true, 'label' => 'Item ID'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Update text'],
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
     * Monday.com signature: authorization header — plain token comparison.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $token = $headers['authorization'] ?? '';

        if ($token === '') {
            return false;
        }

        return hash_equals($secret, $token);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        // Handle Monday's initial webhook challenge-response handshake
        if (isset($payload['challenge'])) {
            return [
                [
                    'source_type' => 'monday',
                    'source_id' => 'monday:challenge',
                    'payload' => ['challenge' => $payload['challenge']],
                    'tags' => ['monday', '_challenge'],
                ],
            ];
        }

        $event = $payload['event'] ?? [];
        $type = $event['type'] ?? 'create_pulse';
        $itemId = $event['pulseId'] ?? $event['itemId'] ?? uniqid('mon_', true);

        $trigger = match ($type) {
            'create_pulse' => 'item_created',
            'update_column_value', 'change_column_value' => 'column_value_changed',
            'change_status_column_value' => 'item_status_changed',
            'create_subitem' => 'subitem_created',
            default => str_replace(' ', '_', strtolower($type)),
        };

        return [
            [
                'source_type' => 'monday',
                'source_id' => 'monday:'.$itemId,
                'payload' => $payload,
                'tags' => ['monday', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        return match ($action) {
            'create_item' => $this->graphql($integration,
                'mutation ($boardId: ID!, $itemName: String!, $groupId: String) {
                    create_item (board_id: $boardId, item_name: $itemName, group_id: $groupId) { id }
                }',
                [
                    'boardId' => $params['board_id'],
                    'itemName' => $params['item_name'],
                    'groupId' => $params['group_id'] ?? null,
                ]
            ),

            'update_status' => $this->graphql($integration,
                'mutation ($itemId: ID!, $boardId: ID!, $columnId: String!, $value: JSON!) {
                    change_simple_column_value (item_id: $itemId, board_id: $boardId, column_id: $columnId, value: $value) { id }
                }',
                [
                    'itemId' => $params['item_id'],
                    'boardId' => $params['board_id'],
                    'columnId' => $params['column_id'],
                    'value' => $params['value'],
                ]
            ),

            'create_update' => $this->graphql($integration,
                'mutation ($itemId: ID!, $body: String!) {
                    create_update (item_id: $itemId, body: $body) { id }
                }',
                ['itemId' => $params['item_id'], 'body' => $params['body']]
            ),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
