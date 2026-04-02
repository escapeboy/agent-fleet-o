<?php

namespace App\Domain\Integration\Drivers\OnePassword;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class OnePasswordIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return '1password';
    }

    public function label(): string
    {
        return '1Password';
    }

    public function description(): string
    {
        return 'Access 1Password vaults via Service Account. List vaults, search items, and resolve secrets at runtime. Agents can read credentials without exposing raw secrets.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'service_account_token' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Service Account Token',
                'hint' => '1Password → Developer → Infrastructure Secrets → Service Accounts → Create',
            ],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['service_account_token'] ?? null;

        return ! empty($token) && str_starts_with($token, 'ops_');
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = (string) $integration->getCredentialSecret('service_account_token');

        try {
            $vaults = $this->apiRequest($token, 'GET', '/vaults');

            return new HealthResult(
                healthy: true,
                message: 'Connected — '.count($vaults).' vault(s) accessible',
            );
        } catch (\Throwable $e) {
            return new HealthResult(healthy: false, message: $e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('list_vaults', 'List Vaults', 'List all vaults accessible to the service account.', []),
            new ActionDefinition('search_items', 'Search Items', 'Search for items across vaults by title.', [
                'query' => ['type' => 'string', 'required' => true, 'label' => 'Search query (item title)'],
                'vault' => ['type' => 'string', 'required' => false, 'label' => 'Vault name or ID (searches all if omitted)'],
            ]),
            new ActionDefinition('get_item', 'Get Item', 'Get full details of a specific item.', [
                'vault_id' => ['type' => 'string', 'required' => true, 'label' => 'Vault ID'],
                'item_id' => ['type' => 'string', 'required' => true, 'label' => 'Item ID'],
            ]),
            new ActionDefinition('resolve_secret', 'Resolve Secret Reference', 'Resolve a 1Password secret reference (op://vault/item/field) to its value.', [
                'reference' => ['type' => 'string', 'required' => true, 'label' => 'Secret reference (e.g. op://vault/item/password)'],
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
        return false;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return false;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = (string) $integration->getCredentialSecret('service_account_token');

        return match ($action) {
            'list_vaults' => $this->listVaults($token),
            'search_items' => $this->searchItems($token, $params),
            'get_item' => $this->getItem($token, $params),
            'resolve_secret' => $this->resolveSecret($token, $params),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function listVaults(string $token): array
    {
        $response = $this->apiRequest($token, 'GET', '/vaults');

        return array_map(fn ($v) => [
            'id' => $v['id'],
            'name' => $v['name'],
            'description' => $v['description'] ?? '',
            'items' => $v['items'] ?? 0,
        ], $response);
    }

    private function searchItems(string $token, array $params): array
    {
        $query = str_replace(['"', '\\'], '', $params['query'] ?? '');
        $vaultId = $params['vault'] ?? null;

        if ($vaultId) {
            $this->assertAlphanumericId($vaultId, 'vault');
            $items = $this->apiRequest($token, 'GET', "/vaults/{$vaultId}/items", [
                'filter' => "title co \"{$query}\"",
            ]);
        } else {
            // Search across all vaults
            $vaults = $this->listVaults($token);
            $items = [];
            foreach (array_slice($vaults, 0, 10) as $vault) {
                $vaultItems = $this->apiRequest($token, 'GET', "/vaults/{$vault['id']}/items", [
                    'filter' => "title co \"{$query}\"",
                ]);
                foreach ($vaultItems as $item) {
                    $item['vault_name'] = $vault['name'];
                    $items[] = $item;
                }
            }
        }

        return array_map(fn ($i) => [
            'id' => $i['id'],
            'title' => $i['title'] ?? '',
            'category' => $i['category'] ?? 'LOGIN',
            'vault_id' => $i['vault']['id'] ?? $vaultId,
            'vault_name' => $i['vault_name'] ?? null,
        ], array_slice($items, 0, 50));
    }

    private function getItem(string $token, array $params): array
    {
        $vaultId = $params['vault_id'] ?? null;
        $itemId = $params['item_id'] ?? null;

        if (! $vaultId || ! $itemId) {
            throw new \InvalidArgumentException('vault_id and item_id are required');
        }

        $this->assertAlphanumericId($vaultId, 'vault_id');
        $this->assertAlphanumericId($itemId, 'item_id');

        $item = $this->apiRequest($token, 'GET', "/vaults/{$vaultId}/items/{$itemId}");

        // Redact actual secret values — return field labels and metadata only
        $fields = array_map(fn ($f) => [
            'id' => $f['id'] ?? '',
            'label' => $f['label'] ?? $f['id'] ?? '',
            'type' => $f['type'] ?? 'STRING',
            'purpose' => $f['purpose'] ?? '',
            'has_value' => ! empty($f['value']),
            'reference' => $f['reference'] ?? null,
        ], $item['fields'] ?? []);

        return [
            'id' => $item['id'],
            'title' => $item['title'] ?? '',
            'category' => $item['category'] ?? '',
            'fields' => $fields,
            'urls' => $item['urls'] ?? [],
            'tags' => $item['tags'] ?? [],
            'created_at' => $item['createdAt'] ?? null,
            'updated_at' => $item['updatedAt'] ?? null,
        ];
    }

    private function resolveSecret(string $token, array $params): array
    {
        $reference = $params['reference'] ?? '';

        // Validate op:// reference format
        if (! preg_match('#^op://([^/]+)/([^/]+)/([^/]+)$#', $reference, $matches)) {
            throw new \InvalidArgumentException('Invalid secret reference. Use format: op://vault/item/field');
        }

        [$full, $vaultName, $itemName, $fieldName] = $matches;

        // Reject segments containing quotes to prevent SCIM filter injection
        foreach ([$vaultName, $itemName, $fieldName] as $segment) {
            if (preg_match('/["\\\]/', $segment)) {
                throw new \InvalidArgumentException('Secret reference segments must not contain quotes or backslashes');
            }
        }

        // Resolve vault ID
        $vaults = $this->apiRequest($token, 'GET', '/vaults', ['filter' => "name eq \"{$vaultName}\""]);
        if (empty($vaults)) {
            throw new \RuntimeException("Vault not found: {$vaultName}");
        }
        $vaultId = $vaults[0]['id'];

        // Resolve item ID
        $items = $this->apiRequest($token, 'GET', "/vaults/{$vaultId}/items", ['filter' => "title eq \"{$itemName}\""]);
        if (empty($items)) {
            throw new \RuntimeException("Item not found: {$itemName}");
        }
        $itemId = $items[0]['id'];

        // Get full item with field values
        $item = $this->apiRequest($token, 'GET', "/vaults/{$vaultId}/items/{$itemId}");

        foreach ($item['fields'] ?? [] as $field) {
            $label = strtolower($field['label'] ?? $field['id'] ?? '');
            if ($label === strtolower($fieldName) || ($field['id'] ?? '') === $fieldName) {
                $value = $field['value'] ?? '';

                return [
                    'reference' => $reference,
                    'resolved' => true,
                    'field_type' => $field['type'] ?? 'STRING',
                    'value_preview' => mb_strlen($value) > 4
                        ? str_repeat('*', mb_strlen($value) - 4).mb_substr($value, -4)
                        : '****',
                    'value_length' => mb_strlen($value),
                ];
            }
        }

        throw new \RuntimeException("Field not found: {$fieldName}");
    }

    /**
     * Validate that an ID is alphanumeric (1Password uses 26-char base32 IDs).
     */
    private function assertAlphanumericId(string $id, string $label): void
    {
        if (! preg_match('/^[a-zA-Z0-9]{1,64}$/', $id)) {
            throw new \InvalidArgumentException("Invalid {$label}: must be alphanumeric");
        }
    }

    /**
     * Make an authenticated request to the 1Password Connect/Service Account API.
     */
    private function apiRequest(string $token, string $method, string $path, array $query = []): array
    {
        // Service Account tokens use the Connect Server API format
        // The base URL is derived from the token or uses the default
        $baseUrl = 'https://api.1password.com/v1';

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ])
            ->timeout(15)
            ->send($method, $baseUrl.$path, [
                'query' => $query,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("1Password API error: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }
}
