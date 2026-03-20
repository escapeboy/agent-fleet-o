<?php

namespace App\Domain\Integration\Drivers\Supabase;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Supabase integration driver.
 *
 * Supports querying tables, executing SQL, invoking Edge Functions,
 * uploading Storage objects, and receiving Database Webhook signals.
 *
 * Authentication: Supabase service role key (bypasses RLS — server-side only).
 * Project URL is stored in metadata (non-secret), key in secret_data.
 *
 * @see https://supabase.com/docs/guides/api
 */
class SupabaseIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'supabase';
    }

    public function label(): string
    {
        return 'Supabase';
    }

    public function description(): string
    {
        return 'Connect your Supabase project — agents can query tables, run SQL, invoke Edge Functions, upload Storage objects, and react to database changes via webhooks.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'project_url' => [
                'type' => 'string',
                'required' => true,
                'label' => 'Project URL',
                'hint' => 'Your Supabase project URL, e.g. https://xyzabcdef.supabase.co',
            ],
            'service_role_key' => [
                'type' => 'password',
                'required' => true,
                'label' => 'Service Role Key',
                'hint' => 'Found in Project Settings → API. Bypasses RLS — keep server-side only.',
            ],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $url = rtrim($credentials['project_url'] ?? '', '/');
        $key = $credentials['service_role_key'] ?? null;

        if (! $url || ! $key) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'apikey' => $key,
                'Authorization' => "Bearer {$key}",
            ])->timeout(10)->get("{$url}/rest/v1/");

            // PostgREST returns 200 with JSON schema on a valid connection
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $url = rtrim($integration->meta['project_url'] ?? '', '/');
        $key = $integration->getCredentialSecret('service_role_key');

        if (! $url) {
            return HealthResult::fail('No project URL configured.');
        }

        if (! $key) {
            return HealthResult::fail('No service role key configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withHeaders([
                'apikey' => $key,
                'Authorization' => "Bearer {$key}",
            ])->timeout(10)->get("{$url}/rest/v1/");

            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail("HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition(
                'table_change',
                'Table Change',
                'Row inserted, updated, or deleted in a Supabase table. Configure a Database Webhook in your Supabase dashboard to push events to FleetQ.',
            ),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('query_table', 'Query Table', 'Fetch rows from a Supabase table via PostgREST.', [
                'table' => ['type' => 'string', 'required' => true],
                'select' => ['type' => 'string', 'required' => false, 'default' => '*'],
                'filters' => ['type' => 'object', 'required' => false],
                'limit' => ['type' => 'integer', 'required' => false, 'default' => 100],
            ]),
            new ActionDefinition('execute_sql', 'Execute SQL', 'Run a raw SQL query via PostgREST RPC.', [
                'function' => ['type' => 'string', 'required' => true],
                'params' => ['type' => 'object', 'required' => false],
            ]),
            new ActionDefinition('invoke_edge_function', 'Invoke Edge Function', 'Call a Supabase Edge Function.', [
                'function_name' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'object', 'required' => false],
            ]),
            new ActionDefinition('upload_storage_object', 'Upload Storage Object', 'Upload a file to a Supabase Storage bucket.', [
                'bucket' => ['type' => 'string', 'required' => true],
                'path' => ['type' => 'string', 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
                'content_type' => ['type' => 'string', 'required' => false, 'default' => 'application/octet-stream'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0; // Webhook-only — no polling
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        // Supabase Database Webhooks send X-Webhook-Secret as a plain header value.
        // The user configures the same secret in both Supabase and FleetQ.
        $providedSecret = $headers['x-webhook-secret'] ?? $headers['X-Webhook-Secret'] ?? '';

        if (empty($providedSecret)) {
            return false;
        }

        return hash_equals($secret, $providedSecret);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        // Supabase Database Webhook payload format:
        // { type: INSERT|UPDATE|DELETE, table: string, schema: string,
        //   record: object|null, old_record: object|null }
        $type = $payload['type'] ?? 'unknown';
        $table = $payload['table'] ?? 'unknown';
        $schema = $payload['schema'] ?? 'public';
        $record = $payload['record'] ?? [];
        $oldRecord = $payload['old_record'] ?? null;

        $sourceId = $schema.'.'.$table.'.'.strtolower($type).'.'.
            ($record['id'] ?? $oldRecord['id'] ?? uniqid('supa_', true));

        return [
            [
                'source_type' => 'supabase_cdc',
                'source_id' => $sourceId,
                'payload' => [
                    'type' => $type,
                    'schema' => $schema,
                    'table' => $table,
                    'record' => $record,
                    'old_record' => $oldRecord,
                ],
                'tags' => ['supabase', 'cdc', strtolower($type), $table],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $url = rtrim($integration->meta['project_url'] ?? '', '/');
        $key = $integration->getCredentialSecret('service_role_key');

        return match ($action) {
            'query_table' => $this->queryTable($url, $key, $params),
            'execute_sql' => $this->executeSql($url, $key, $params),
            'invoke_edge_function' => $this->invokeEdgeFunction($url, $key, $params),
            'upload_storage_object' => $this->uploadStorageObject($url, $key, $params),
            default => throw new \InvalidArgumentException("Unknown Supabase action: {$action}"),
        };
    }

    private function queryTable(?string $url, ?string $key, array $params): array
    {
        $table = $params['table'];
        $select = $params['select'] ?? '*';
        $limit = $params['limit'] ?? 100;
        $filters = $params['filters'] ?? [];

        $query = array_merge(['select' => $select, 'limit' => $limit], $filters);

        $response = Http::withHeaders([
            'apikey' => $key,
            'Authorization' => "Bearer {$key}",
        ])->timeout(15)->get("{$url}/rest/v1/{$table}", $query);

        return $response->json() ?? [];
    }

    private function executeSql(?string $url, ?string $key, array $params): mixed
    {
        $function = $params['function'];
        $rpcParams = $params['params'] ?? [];

        $response = Http::withHeaders([
            'apikey' => $key,
            'Authorization' => "Bearer {$key}",
            'Content-Type' => 'application/json',
        ])->timeout(30)->post("{$url}/rest/v1/rpc/{$function}", $rpcParams);

        return $response->json();
    }

    private function invokeEdgeFunction(?string $url, ?string $key, array $params): mixed
    {
        $functionName = $params['function_name'];
        $body = $params['body'] ?? [];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$key}",
            'Content-Type' => 'application/json',
        ])->timeout(120)->post("{$url}/functions/v1/{$functionName}", $body);

        return $response->json() ?? ['status' => $response->status(), 'body' => $response->body()];
    }

    private function uploadStorageObject(?string $url, ?string $key, array $params): array
    {
        $bucket = $params['bucket'];
        $path = $params['path'];
        $content = $params['content'];
        $contentType = $params['content_type'] ?? 'application/octet-stream';

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$key}",
            'Content-Type' => $contentType,
            'x-upsert' => 'true',
        ])->timeout(30)->withBody($content, $contentType)
            ->post("{$url}/storage/v1/object/{$bucket}/{$path}");

        return $response->json() ?? ['key' => "{$bucket}/{$path}", 'status' => $response->status()];
    }
}
