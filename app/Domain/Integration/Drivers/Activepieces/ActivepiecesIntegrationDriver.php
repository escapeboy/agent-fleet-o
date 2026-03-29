<?php

namespace App\Domain\Integration\Drivers\Activepieces;

use App\Domain\Integration\Actions\SyncActivepiecesToolsAction;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Activepieces integration driver.
 *
 * Activepieces is an open-source no-code automation platform.
 * This driver connects FleetQ to a self-hosted or cloud Activepieces instance,
 * exposing each installed piece as an MCP-HTTP Tool via the Activepieces MCP API.
 *
 * Authentication: API key (Bearer token).
 * No webhook support (polling-only via sync job).
 */
class ActivepiecesIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'activepieces';
    }

    public function label(): string
    {
        return 'Activepieces';
    }

    public function description(): string
    {
        return 'Connect to a self-hosted or cloud Activepieces instance to expose automation pieces as MCP tools for your agents.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'base_url' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Base URL',
                'hint' => 'e.g. https://activepieces.yourcompany.com',
            ],
            'api_key' => [
                'type' => 'string',
                'required' => true,
                'label' => 'API Key',
                'hint' => 'Found in Activepieces → Settings → API Keys',
            ],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $baseUrl = rtrim((string) ($credentials['base_url'] ?? ''), '/');
        $apiKey = (string) ($credentials['api_key'] ?? '');

        if (! $baseUrl || ! $apiKey) {
            return false;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->get("{$baseUrl}/api/v1/pieces", ['release' => 'latest', 'limit' => 1]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $baseUrl = rtrim((string) ($integration->getCredentialSecret('base_url') ?? $integration->config['base_url'] ?? ''), '/');
        $apiKey = (string) ($integration->getCredentialSecret('api_key') ?? '');

        if (! $baseUrl) {
            return HealthResult::fail('base_url is not configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->get("{$baseUrl}/api/v1/pieces", ['release' => 'latest', 'limit' => 1]);

            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail("HTTP {$response->status()}: ".$response->body());
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        // Activepieces events are handled via the MCP tool layer, not via inbound webhooks.
        return [];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition(
                key: 'sync_pieces',
                label: 'Sync Pieces',
                description: 'Fetch the latest piece catalogue from Activepieces and upsert matching MCP-HTTP Tools.',
                inputSchema: [],
            ),
        ];
    }

    public function pollFrequency(): int
    {
        // No polling — sync is triggered by the hourly artisan command or explicitly.
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
        // Activepieces does not deliver webhooks to FleetQ — always return false.
        return false;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    /**
     * Execute a named action on this integration.
     *
     * Supported action: 'sync_pieces' — triggers an immediate piece sync.
     */
    public function execute(Integration $integration, string $action, array $params): mixed
    {
        if ($action === 'sync_pieces') {
            $syncAction = app(SyncActivepiecesToolsAction::class);

            return $syncAction->execute($integration);
        }

        throw new \RuntimeException("Unsupported action '{$action}' for Activepieces driver.");
    }
}
