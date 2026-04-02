<?php

namespace App\Domain\Integration\Drivers\Apify;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class ApifyIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.apify.com/v2';

    public function key(): string
    {
        return 'apify';
    }

    public function label(): string
    {
        return 'Apify';
    }

    public function description(): string
    {
        return 'Run 10,000+ pre-built web scrapers, data extraction tools, and automation actors from Apify Store. Agents can scrape websites, extract data, and automate browser tasks.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_token' => [
                'type' => 'string',
                'required' => true,
                'label' => 'API Token',
                'hint' => 'console.apify.com → Settings → Integrations → API Token',
            ],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['api_token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('api_token');

        if (! $token) {
            return HealthResult::fail('No API token configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');

            $latency = (int) ((microtime(true) - $start) * 1000);

            if (! $response->successful()) {
                return HealthResult::fail("HTTP {$response->status()}");
            }

            $user = $response->json();

            return HealthResult::ok($latency);
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('actor.run.succeeded', 'Actor Run Succeeded', 'An Apify actor run completed successfully.'),
            new TriggerDefinition('actor.run.failed', 'Actor Run Failed', 'An Apify actor run failed.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('run_actor', 'Run Actor', 'Run any Apify actor by ID with given input.', [
                'actor_id' => ['type' => 'string', 'required' => true, 'label' => 'Actor ID (e.g. apify/web-scraper)'],
                'input' => ['type' => 'object', 'required' => false, 'label' => 'Actor input JSON'],
                'wait_secs' => ['type' => 'integer', 'required' => false, 'label' => 'Max wait seconds (0 = async)', 'default' => 60],
                'memory_mbytes' => ['type' => 'integer', 'required' => false, 'label' => 'Memory allocation (MB)', 'default' => 256],
            ]),
            new ActionDefinition('get_run', 'Get Run Status', 'Check the status and results of an actor run.', [
                'run_id' => ['type' => 'string', 'required' => true, 'label' => 'Run ID'],
            ]),
            new ActionDefinition('get_dataset', 'Get Dataset Items', 'Retrieve items from a dataset (actor output).', [
                'dataset_id' => ['type' => 'string', 'required' => true, 'label' => 'Dataset ID'],
                'limit' => ['type' => 'integer', 'required' => false, 'label' => 'Max items to return', 'default' => 100],
            ]),
            new ActionDefinition('search_store', 'Search Actor Store', 'Search Apify Store for actors by keyword.', [
                'query' => ['type' => 'string', 'required' => true, 'label' => 'Search query'],
                'limit' => ['type' => 'integer', 'required' => false, 'label' => 'Max results', 'default' => 10],
            ]),
            new ActionDefinition('list_actors', 'List My Actors', 'List actors in your Apify account.', []),
            new ActionDefinition('get_actor_info', 'Get Actor Info', 'Get detailed info about an actor including input schema.', [
                'actor_id' => ['type' => 'string', 'required' => true, 'label' => 'Actor ID (e.g. apify/web-scraper)'],
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

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        // Apify webhooks don't use HMAC — they pass a secret token via URL query param.
        // If no secret is configured, skip verification. If a secret IS configured,
        // verify it matches the X-Apify-Webhook-Secret header (set by Apify when configuring).
        if (empty($secret)) {
            return true;
        }

        $headerSecret = $headers['x-apify-webhook-secret'] ?? $headers['X-Apify-Webhook-Secret'] ?? '';

        return hash_equals($secret, $headerSecret);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $eventType = $payload['eventType'] ?? 'unknown';
        $runId = $payload['resource']['id'] ?? uniqid('apify_', true);

        return [
            [
                'source_type' => 'apify',
                'source_id' => $runId,
                'payload' => $payload,
                'tags' => ['apify', $eventType],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = (string) $integration->getCredentialSecret('api_token');

        return match ($action) {
            'run_actor' => $this->runActor($token, $params),
            'get_run' => $this->getRun($token, $params),
            'get_dataset' => $this->getDataset($token, $params),
            'search_store' => $this->searchStore($token, $params),
            'list_actors' => $this->listActors($token),
            'get_actor_info' => $this->getActorInfo($token, $params),
            default => throw new \InvalidArgumentException("Unknown Apify action: {$action}"),
        };
    }

    private function runActor(string $token, array $params): array
    {
        $actorId = $params['actor_id'];
        $input = $params['input'] ?? [];
        $waitSecs = min((int) ($params['wait_secs'] ?? 60), 300);
        $memoryMbytes = min((int) ($params['memory_mbytes'] ?? 256), 4096);

        $url = self::API_BASE.'/acts/'.urlencode($actorId).'/runs';

        $query = array_filter([
            'waitForFinish' => $waitSecs > 0 ? $waitSecs : null,
            'memory' => $memoryMbytes,
        ]);

        $response = Http::withToken($token)
            ->timeout(max($waitSecs + 10, 30))
            ->post($url.'?'.http_build_query($query), $input);

        if (! $response->successful()) {
            return ['error' => "Apify API error: HTTP {$response->status()}", 'body' => $response->json()];
        }

        $run = $response->json('data', []);

        $result = [
            'run_id' => $run['id'] ?? null,
            'status' => $run['status'] ?? 'UNKNOWN',
            'actor_id' => $actorId,
            'dataset_id' => $run['defaultDatasetId'] ?? null,
            'started_at' => $run['startedAt'] ?? null,
            'finished_at' => $run['finishedAt'] ?? null,
        ];

        // If the run finished and we have a dataset, fetch results
        if (($run['status'] ?? '') === 'SUCCEEDED' && ! empty($run['defaultDatasetId'])) {
            $result['items'] = $this->getDataset($token, [
                'dataset_id' => $run['defaultDatasetId'],
                'limit' => 50,
            ])['items'] ?? [];
        }

        return $result;
    }

    private function getRun(string $token, array $params): array
    {
        $runId = $params['run_id'];

        $response = Http::withToken($token)
            ->timeout(15)
            ->get(self::API_BASE.'/actor-runs/'.urlencode($runId));

        if (! $response->successful()) {
            return ['error' => "HTTP {$response->status()}"];
        }

        $run = $response->json('data', []);

        return [
            'run_id' => $run['id'] ?? null,
            'status' => $run['status'] ?? 'UNKNOWN',
            'dataset_id' => $run['defaultDatasetId'] ?? null,
            'started_at' => $run['startedAt'] ?? null,
            'finished_at' => $run['finishedAt'] ?? null,
            'usage_usd' => $run['usageTotalUsd'] ?? null,
        ];
    }

    private function getDataset(string $token, array $params): array
    {
        $datasetId = $params['dataset_id'];
        $limit = (int) ($params['limit'] ?? 100);

        $response = Http::withToken($token)
            ->timeout(30)
            ->get(self::API_BASE.'/datasets/'.urlencode($datasetId).'/items', [
                'limit' => $limit,
                'format' => 'json',
            ]);

        if (! $response->successful()) {
            return ['error' => "HTTP {$response->status()}", 'items' => []];
        }

        return ['items' => $response->json() ?? [], 'count' => count($response->json() ?? [])];
    }

    private function searchStore(string $token, array $params): array
    {
        $query = $params['query'];
        $limit = (int) ($params['limit'] ?? 10);

        $response = Http::withToken($token)
            ->timeout(15)
            ->get(self::API_BASE.'/store', [
                'search' => $query,
                'limit' => $limit,
                'sortBy' => 'relevance',
            ]);

        if (! $response->successful()) {
            return ['error' => "HTTP {$response->status()}", 'actors' => []];
        }

        $items = $response->json('data.items', []);

        return [
            'actors' => array_map(fn ($a) => [
                'id' => $a['id'] ?? null,
                'name' => $a['name'] ?? null,
                'title' => $a['title'] ?? null,
                'description' => $a['description'] ?? null,
                'username' => $a['username'] ?? null,
                'runs' => $a['stats']['totalRuns'] ?? 0,
                'users' => $a['stats']['totalUsers'] ?? 0,
            ], $items),
            'total' => count($items),
        ];
    }

    private function listActors(string $token): array
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->get(self::API_BASE.'/acts', ['limit' => 50]);

        if (! $response->successful()) {
            return ['error' => "HTTP {$response->status()}", 'actors' => []];
        }

        $items = $response->json('data.items', []);

        return [
            'actors' => array_map(fn ($a) => [
                'id' => $a['id'] ?? null,
                'name' => $a['name'] ?? null,
                'title' => $a['title'] ?? null,
                'created_at' => $a['createdAt'] ?? null,
            ], $items),
        ];
    }

    private function getActorInfo(string $token, array $params): array
    {
        $actorId = $params['actor_id'];

        $response = Http::withToken($token)
            ->timeout(15)
            ->get(self::API_BASE.'/acts/'.urlencode($actorId));

        if (! $response->successful()) {
            return ['error' => "HTTP {$response->status()}"];
        }

        $actor = $response->json('data', []);

        return [
            'id' => $actor['id'] ?? null,
            'name' => $actor['name'] ?? null,
            'title' => $actor['title'] ?? null,
            'description' => $actor['description'] ?? null,
            'default_run_options' => $actor['defaultRunOptions'] ?? [],
            'input_schema' => $actor['defaultRunInput'] ?? null,
            'readme' => mb_substr($actor['readme'] ?? '', 0, 2000),
        ];
    }
}
