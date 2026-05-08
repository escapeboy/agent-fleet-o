<?php

namespace App\Domain\Integration\Drivers\OnePassword;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use App\Infrastructure\Secrets\OnePasswordResolver;
use Illuminate\Support\Facades\Process;

/**
 * 1Password integration driver — uses the `op` CLI v2 (installed in the
 * Docker image) so Service Account tokens work as advertised.
 *
 * Why not the public REST API? 1Password's `api.1password.com` is internal —
 * Service Account tokens authenticate only against (a) the official SDKs
 * (Go/JS/Python — no PHP), (b) `op` CLI, or (c) a self-hosted Connect Server.
 * The CLI is the only path that works for cloud BYOK without per-team
 * infrastructure.
 *
 * @see https://developer.1password.com/docs/cli/reference/
 * @see https://developer.1password.com/docs/service-accounts/
 */
class OnePasswordIntegrationDriver implements IntegrationDriverInterface
{
    public const DEFAULT_TIMEOUT_SECONDS = 15;

    public function __construct(private OnePasswordResolver $resolver) {}

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
        return 'Access 1Password vaults via Service Account. List vaults, search items, and resolve secrets at runtime via the `op` CLI. Agents can read credentials without exposing raw secrets.';
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

        return ! empty($token) && str_starts_with($token, 'ops_') && strlen($token) >= 32;
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = (string) $integration->getCredentialSecret('service_account_token');

        try {
            $vaults = $this->opJson($token, ['op', 'vault', 'list', '--format=json']);

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
            new ActionDefinition('get_item', 'Get Item', 'Get full details of a specific item (field values redacted).', [
                'vault' => ['type' => 'string', 'required' => true, 'label' => 'Vault name or ID'],
                'item' => ['type' => 'string', 'required' => true, 'label' => 'Item name or ID'],
            ]),
            new ActionDefinition('resolve_secret', 'Resolve Secret Reference', 'Resolve a 1Password secret reference (op://vault/item/field). Returns a masked preview only — actual values are only available to internal credential resolution paths.', [
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
            'resolve_secret' => $this->resolveSecretMasked($token, $params),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function listVaults(string $token): array
    {
        $vaults = $this->opJson($token, ['op', 'vault', 'list', '--format=json']);

        return array_map(fn ($v) => [
            'id' => $v['id'] ?? '',
            'name' => $v['name'] ?? '',
            'description' => $v['description'] ?? '',
            'items' => $v['items'] ?? null,
        ], $vaults);
    }

    private function searchItems(string $token, array $params): array
    {
        $query = (string) ($params['query'] ?? '');
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }
        $vault = $params['vault'] ?? null;

        $cmd = ['op', 'item', 'list', '--format=json'];
        if ($vault) {
            $cmd[] = '--vault';
            $cmd[] = (string) $vault;
        }

        $items = $this->opJson($token, $cmd);

        // Filter client-side by title `co` (contains) — `op item list` doesn't
        // support a server-side filter param.
        $needle = mb_strtolower($query);
        $matches = array_values(array_filter(
            $items,
            fn ($i) => str_contains(mb_strtolower((string) ($i['title'] ?? '')), $needle),
        ));

        return array_map(fn ($i) => [
            'id' => $i['id'] ?? '',
            'title' => $i['title'] ?? '',
            'category' => $i['category'] ?? 'LOGIN',
            'vault_id' => $i['vault']['id'] ?? null,
            'vault_name' => $i['vault']['name'] ?? null,
        ], array_slice($matches, 0, 50));
    }

    private function getItem(string $token, array $params): array
    {
        $vault = $params['vault'] ?? null;
        $item = $params['item'] ?? null;

        if (! $vault || ! $item) {
            throw new \InvalidArgumentException('vault and item are required');
        }

        // op accepts vault/item by ID or name.
        $cmd = ['op', 'item', 'get', (string) $item, '--vault', (string) $vault, '--format=json'];
        $itemData = $this->opJson($token, $cmd);

        // Field metadata only — values are NEVER returned raw via this user-facing tool.
        $fields = array_map(fn ($f) => [
            'id' => $f['id'] ?? '',
            'label' => $f['label'] ?? $f['id'] ?? '',
            'type' => $f['type'] ?? 'STRING',
            'purpose' => $f['purpose'] ?? '',
            'has_value' => isset($f['value']) && $f['value'] !== '',
            'reference' => $f['reference'] ?? null,
        ], $itemData['fields'] ?? []);

        return [
            'id' => $itemData['id'] ?? '',
            'title' => $itemData['title'] ?? '',
            'category' => $itemData['category'] ?? '',
            'fields' => $fields,
            'urls' => $itemData['urls'] ?? [],
            'tags' => $itemData['tags'] ?? [],
            'created_at' => $itemData['created_at'] ?? null,
            'updated_at' => $itemData['updated_at'] ?? null,
        ];
    }

    /**
     * User-facing wrapper: returns only a masked preview, never the raw secret.
     * Internal callers that need the actual value should inject and call
     * {@see OnePasswordResolver::resolve()} directly.
     */
    private function resolveSecretMasked(string $token, array $params): array
    {
        $reference = (string) ($params['reference'] ?? '');

        $value = $this->resolver->resolve($reference, $token);
        $length = mb_strlen($value);

        return [
            'reference' => $reference,
            'resolved' => true,
            'value_preview' => $length > 4
                ? str_repeat('*', $length - 4).mb_substr($value, -4)
                : '****',
            'value_length' => $length,
        ];
    }

    /**
     * Run an `op` command and decode JSON output.
     *
     * @param  list<string>  $command
     * @return array<int|string, mixed>
     */
    private function opJson(string $token, array $command): array
    {
        $result = Process::env([
            'OP_SERVICE_ACCOUNT_TOKEN' => $token,
            'OP_FORMAT' => 'json',
            'NO_COLOR' => '1',
        ])
            ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
            ->run($command);

        if (! $result->successful()) {
            throw new \RuntimeException(
                '1Password CLI error (exit '.$result->exitCode().'): '
                .trim($result->errorOutput() ?: $result->output()),
            );
        }

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('1Password CLI returned invalid JSON');
        }

        return $decoded;
    }
}
