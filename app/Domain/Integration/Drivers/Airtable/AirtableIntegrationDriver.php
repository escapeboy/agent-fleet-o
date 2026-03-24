<?php

namespace App\Domain\Integration\Drivers\Airtable;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class AirtableIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.airtable.com/v0';

    public function key(): string
    {
        return 'airtable';
    }

    public function label(): string
    {
        return 'Airtable';
    }

    public function description(): string
    {
        return 'Poll Airtable bases for new or modified records and create/update records from AI output.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? $credentials['token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/meta/bases');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');

        if (! $token) {
            return HealthResult::fail('No token configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/meta/bases');
            $latency = (int) ((microtime(true) - $start) * 1000);

            return $response->successful()
                ? HealthResult::ok($latency)
                : HealthResult::fail("HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('record_created', 'Record Created', 'A new record was added to an Airtable table (polled).'),
            new TriggerDefinition('record_updated', 'Record Updated', 'A record was modified (polled).'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_record', 'Create Record', 'Create a new record in a table.', [
                'base_id' => ['type' => 'string', 'required' => true],
                'table' => ['type' => 'string', 'required' => true],
                'fields' => ['type' => 'object', 'required' => true],
            ]),
            new ActionDefinition('update_record', 'Update Record', 'Update an existing record.', [
                'base_id' => ['type' => 'string', 'required' => true],
                'table' => ['type' => 'string', 'required' => true],
                'record_id' => ['type' => 'string', 'required' => true],
                'fields' => ['type' => 'object', 'required' => true],
            ]),
            new ActionDefinition('list_records', 'List Records', 'List records from a table.', [
                'base_id' => ['type' => 'string', 'required' => true],
                'table' => ['type' => 'string', 'required' => true],
                'max_records' => ['type' => 'integer', 'required' => false],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        /** @var array<string, mixed> $config */
        $config = $integration->config ?? [];
        $baseId = $config['base_id'] ?? null;
        $table = $config['table'] ?? null;
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');

        if (! $baseId || ! $table || ! $token) {
            return [];
        }

        $params = array_filter([
            'offset' => $config['poll_offset'] ?? null,
            'filterByFormula' => $config['filter_formula'] ?? null,
            'maxRecords' => 20,
        ]);

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->get(self::API_BASE."/{$baseId}/{$table}", $params);

            if (! $response->successful()) {
                return [];
            }

            $records = $response->json('records', []);
            $offset = $response->json('offset');

            if ($offset) {
                $config['poll_offset'] = $offset;
                $integration->update(['config' => $config]);
            }

            return array_map(fn ($record) => [
                'source_type' => 'airtable',
                'source_id' => $record['id'],
                'payload' => $record,
                'tags' => ['airtable', $table],
            ], $records);
        } catch (\Throwable) {
            return [];
        }
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
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('token');

        return match ($action) {
            'create_record' => Http::withToken((string) $token)
                ->post(self::API_BASE."/{$params['base_id']}/{$params['table']}", ['fields' => $params['fields']])
                ->json(),
            'update_record' => Http::withToken((string) $token)
                ->patch(self::API_BASE."/{$params['base_id']}/{$params['table']}/{$params['record_id']}", ['fields' => $params['fields']])
                ->json(),
            'list_records' => Http::withToken((string) $token)
                ->get(self::API_BASE."/{$params['base_id']}/{$params['table']}", array_filter(['maxRecords' => $params['max_records'] ?? 20]))
                ->json('records', []),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
