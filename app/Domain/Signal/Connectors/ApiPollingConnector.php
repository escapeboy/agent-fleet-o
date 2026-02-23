<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiPollingConnector implements InputConnectorInterface
{
    private const MAX_SEEN_IDS = 1000;

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $url = $config['url'] ?? null;
        $method = strtoupper($config['method'] ?? 'GET');
        $authType = $config['auth_type'] ?? null;
        $credentialId = $config['credential_id'] ?? null;
        $maxPerPoll = min($config['max_per_poll'] ?? 100, 500);
        $experimentId = $config['experiment_id'] ?? null;
        $tags = $config['tags'] ?? ['api'];

        // Response mapping
        $itemsPath = $config['items_path'] ?? null;
        $idPath = $config['id_path'] ?? 'id';
        $titlePath = $config['title_path'] ?? 'title';
        $contentPath = $config['content_path'] ?? 'description';

        // Dedup tracking
        $lastSeenIds = $config['last_seen_ids'] ?? [];

        if (! $url) {
            Log::warning('ApiPollingConnector: No URL provided');

            return [];
        }

        try {
            $request = Http::timeout(30)->acceptJson();
            $request = $this->applyAuth($request, $authType, $credentialId, $config);

            // Add custom headers
            if (! empty($config['headers'])) {
                $request = $request->withHeaders($config['headers']);
            }

            $response = match ($method) {
                'POST' => $request->post($url, $config['body_template'] ?? []),
                default => $request->get($url),
            };

            if (! $response->successful()) {
                Log::warning('ApiPollingConnector: Request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            $items = $itemsPath ? Arr::get($data, $itemsPath, []) : $data;

            if (! is_array($items)) {
                Log::warning('ApiPollingConnector: Items not an array', ['items_path' => $itemsPath]);

                return [];
            }

            $signals = [];
            $newSeenIds = $lastSeenIds;

            foreach (array_slice($items, 0, $maxPerPoll) as $item) {
                $itemId = (string) Arr::get($item, $idPath, '');
                if (! $itemId) {
                    continue;
                }

                // Skip already seen items
                if (in_array($itemId, $lastSeenIds, true)) {
                    continue;
                }

                $title = Arr::get($item, $titlePath, '');
                $content = Arr::get($item, $contentPath, '');

                $payload = [
                    'item_id' => $itemId,
                    'title' => $title,
                    'content' => is_string($content) ? $content : json_encode($content),
                    'raw_item' => $item,
                ];

                $signal = $this->ingestAction->execute(
                    sourceType: 'api',
                    sourceIdentifier: $url,
                    payload: $payload,
                    tags: $tags,
                    experimentId: $experimentId,
                );

                if ($signal) {
                    $signals[] = $signal;
                }

                $newSeenIds[] = $itemId;
            }

            // Handle cursor-based pagination tracking
            $nextCursorPath = $config['next_cursor_path'] ?? null;
            if ($nextCursorPath) {
                $config['last_cursor'] = Arr::get($data, $nextCursorPath);
            }

            // Trim seen IDs to prevent unbounded growth
            if (count($newSeenIds) > self::MAX_SEEN_IDS) {
                $newSeenIds = array_slice($newSeenIds, -self::MAX_SEEN_IDS);
            }

            $config['last_seen_ids'] = $newSeenIds;

            return $signals;
        } catch (\Throwable $e) {
            Log::error('ApiPollingConnector: Error polling API', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'api_polling';
    }

    /**
     * Return the updated config with new last_seen_ids.
     */
    public function getUpdatedConfig(array $config, array $signals): array
    {
        $lastSeenIds = $config['last_seen_ids'] ?? [];

        foreach ($signals as $signal) {
            $itemId = $signal->payload['item_id'] ?? null;
            if ($itemId && ! in_array($itemId, $lastSeenIds, true)) {
                $lastSeenIds[] = $itemId;
            }
        }

        if (count($lastSeenIds) > self::MAX_SEEN_IDS) {
            $lastSeenIds = array_slice($lastSeenIds, -self::MAX_SEEN_IDS);
        }

        $config['last_seen_ids'] = $lastSeenIds;

        return $config;
    }

    private function applyAuth($request, ?string $authType, ?string $credentialId, array $config)
    {
        if (! $authType || ! $credentialId) {
            return $request;
        }

        $credentials = $this->resolveCredentials($credentialId);
        if (! $credentials) {
            return $request;
        }

        return match ($authType) {
            'bearer' => $request->withToken($credentials['token'] ?? $credentials['api_key'] ?? ''),
            'api_key' => $request->withHeaders([
                ($credentials['header_name'] ?? 'X-API-Key') => $credentials['api_key'] ?? '',
            ]),
            'basic' => $request->withBasicAuth(
                $credentials['username'] ?? '',
                $credentials['password'] ?? '',
            ),
            default => $request,
        };
    }

    private function resolveCredentials(?string $credentialId): ?array
    {
        if (! $credentialId) {
            return null;
        }

        $credential = TeamProviderCredential::find($credentialId);
        if (! $credential || ! $credential->is_active) {
            return null;
        }

        return $credential->credentials;
    }
}
