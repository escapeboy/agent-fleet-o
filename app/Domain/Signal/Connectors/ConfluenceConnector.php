<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Knowledge\Actions\IngestKnowledgeDocumentAction;
use App\Domain\Signal\Contracts\KnowledgeConnectorInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ConfluenceConnector implements KnowledgeConnectorInterface
{
    private const REDIS_KEY_PREFIX = 'knowledge_sync:';

    public function __construct(
        private readonly IngestKnowledgeDocumentAction $ingestAction,
    ) {}

    public function getDriverName(): string
    {
        return 'confluence';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'confluence';
    }

    public function isKnowledgeConnector(): bool
    {
        return true;
    }

    public function getLastSyncAt(string $bindingId): ?Carbon
    {
        $value = Redis::get(self::REDIS_KEY_PREFIX.$bindingId);

        return $value ? Carbon::parse($value) : null;
    }

    public function setLastSyncAt(string $bindingId, Carbon $at): void
    {
        Redis::setex(self::REDIS_KEY_PREFIX.$bindingId, 60 * 60 * 24 * 90, $at->toIso8601String());
    }

    /**
     * Poll Confluence spaces for recently updated pages and ingest as Memory entries.
     *
     * Config keys:
     *   - confluence_url: Base URL, e.g. https://mycompany.atlassian.net
     *   - email: Atlassian account email
     *   - api_token: Atlassian API token
     *   - space_keys: comma-separated space keys
     *   - team_id: the owning team
     *   - binding_id: connector binding UUID
     *
     * @return array Always returns empty — knowledge connectors write to Memory directly.
     */
    public function poll(array $config): array
    {
        $baseUrl = rtrim($config['confluence_url'] ?? '', '/');
        $email = $config['email'] ?? null;
        $apiToken = $config['api_token'] ?? null;
        $spaceKeys = $config['space_keys'] ?? '';
        $teamId = $config['team_id'] ?? null;
        $bindingId = $config['binding_id'] ?? 'confluence_default';

        if (! $baseUrl || ! $email || ! $apiToken || ! $teamId) {
            Log::warning('ConfluenceConnector: Missing required config', ['binding_id' => $bindingId]);

            return [];
        }

        if (! $this->isSafeUrl($baseUrl)) {
            Log::warning('ConfluenceConnector: Blocked SSRF attempt — URL targets a private/internal address', [
                'binding_id' => $bindingId,
                'url' => $baseUrl,
            ]);

            return [];
        }

        $keys = array_filter(array_map('trim', explode(',', $spaceKeys)));
        if (empty($keys)) {
            Log::warning('ConfluenceConnector: No space_keys configured', ['binding_id' => $bindingId]);

            return [];
        }

        $lastSync = $this->getLastSyncAt($bindingId) ?? now()->subDays(30);
        $syncTime = now();
        $ingested = 0;

        foreach ($keys as $spaceKey) {
            try {
                $ingested += $this->pollSpace($baseUrl, $email, $apiToken, $spaceKey, $teamId, $lastSync);
            } catch (\Throwable $e) {
                Log::error('ConfluenceConnector: Error polling space', [
                    'space_key' => $spaceKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->setLastSyncAt($bindingId, $syncTime);

        Log::info('ConfluenceConnector: Sync complete', [
            'binding_id' => $bindingId,
            'ingested' => $ingested,
        ]);

        return [];
    }

    /**
     * Returns false when the URL resolves to a private/link-local/loopback address
     * to prevent SSRF attacks via a user-controlled confluence_url parameter.
     */
    private function isSafeUrl(string $url): bool
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host = strtolower($parsed['host'] ?? '');

        if (! in_array($scheme, ['https', 'http'], true)) {
            return false;
        }

        if (empty($host)) {
            return false;
        }

        // Reject IP literals that map to private/link-local/loopback ranges
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (
                filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            ) {
                return false;
            }
        }

        // Reject well-known internal hostnames
        $blocked = ['localhost', 'metadata.google.internal', '169.254.169.254'];
        if (in_array($host, $blocked, true)) {
            return false;
        }

        return true;
    }

    private function pollSpace(
        string $baseUrl,
        string $email,
        string $apiToken,
        string $spaceKey,
        string $teamId,
        Carbon $lastSync,
    ): int {
        $ingested = 0;
        $start = 0;
        $limit = 50;

        do {
            $response = Http::timeout(30)
                ->withBasicAuth($email, $apiToken)
                ->get("{$baseUrl}/wiki/rest/api/content", [
                    'spaceKey' => $spaceKey,
                    'type' => 'page',
                    'expand' => 'body.storage,title,history.lastUpdated',
                    'limit' => $limit,
                    'start' => $start,
                ]);

            if (! $response->successful()) {
                Log::warning('ConfluenceConnector: Failed to fetch pages', [
                    'space_key' => $spaceKey,
                    'status' => $response->status(),
                ]);

                return $ingested;
            }

            $data = $response->json();
            $pages = $data['results'] ?? [];

            foreach ($pages as $page) {
                try {
                    $lastUpdated = $page['history']['lastUpdated']['when'] ?? null;
                    if ($lastUpdated && Carbon::parse($lastUpdated)->lt($lastSync)) {
                        continue;
                    }

                    $title = $page['title'] ?? 'Untitled';
                    $rawContent = $page['body']['storage']['value'] ?? '';
                    $content = strip_tags($rawContent);

                    $pageId = $page['id'] ?? '';
                    $pageUrl = $pageId
                        ? "{$baseUrl}/wiki/pages/{$pageId}"
                        : "{$baseUrl}/wiki/spaces/{$spaceKey}";

                    $this->ingestAction->execute(
                        teamId: $teamId,
                        title: $title,
                        content: $content,
                        sourceUrl: $pageUrl,
                        sourceName: 'confluence',
                    );
                    $ingested++;
                } catch (\Throwable $e) {
                    Log::error('ConfluenceConnector: Error ingesting page', [
                        'page_id' => $page['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $total = $data['size'] ?? 0;
            $start += $limit;
        } while (count($pages) === $limit && $start < $total);

        return $ingested;
    }
}
