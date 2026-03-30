<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Knowledge\Actions\IngestKnowledgeDocumentAction;
use App\Domain\Signal\Contracts\KnowledgeConnectorInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NotionConnector implements KnowledgeConnectorInterface
{
    private const REDIS_KEY_PREFIX = 'knowledge_sync:';

    private const NOTION_API_VERSION = '2022-06-28';

    public function __construct(
        private readonly IngestKnowledgeDocumentAction $ingestAction,
    ) {}

    public function getDriverName(): string
    {
        return 'notion';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'notion';
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
     * Poll Notion databases for recently updated pages and ingest as Memory entries.
     *
     * Config keys:
     *   - notion_token: Notion integration secret
     *   - database_ids: comma-separated Notion database UUIDs
     *   - team_id: the owning team (required)
     *   - binding_id: connector binding UUID (used for lastSyncAt tracking)
     *
     * @return array Always returns empty — knowledge connectors write to Memory directly.
     */
    public function poll(array $config): array
    {
        $token = $config['notion_token'] ?? null;
        $databaseIds = $config['database_ids'] ?? '';
        $teamId = $config['team_id'] ?? null;
        $bindingId = $config['binding_id'] ?? 'notion_default';

        if (! $token || ! $teamId) {
            Log::warning('NotionConnector: Missing notion_token or team_id', ['binding_id' => $bindingId]);

            return [];
        }

        $ids = array_filter(array_map('trim', explode(',', $databaseIds)));
        if (empty($ids)) {
            Log::warning('NotionConnector: No database_ids configured', ['binding_id' => $bindingId]);

            return [];
        }

        $lastSync = $this->getLastSyncAt($bindingId) ?? now()->subDays(30);
        $syncTime = now();
        $ingested = 0;

        foreach ($ids as $databaseId) {
            try {
                $ingested += $this->pollDatabase($databaseId, $token, $teamId, $lastSync);
            } catch (\Throwable $e) {
                Log::error('NotionConnector: Error polling database', [
                    'database_id' => $databaseId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->setLastSyncAt($bindingId, $syncTime);

        Log::info('NotionConnector: Sync complete', [
            'binding_id' => $bindingId,
            'ingested' => $ingested,
        ]);

        return [];
    }

    private function pollDatabase(string $databaseId, string $token, string $teamId, Carbon $lastSync): int
    {
        $cursor = null;
        $ingested = 0;

        do {
            $body = [
                'filter' => [
                    'timestamp' => 'last_edited_time',
                    'last_edited_time' => [
                        'after' => $lastSync->toIso8601String(),
                    ],
                ],
                'page_size' => 100,
            ];

            if ($cursor) {
                $body['start_cursor'] = $cursor;
            }

            $response = Http::timeout(30)
                ->withToken($token)
                ->withHeaders(['Notion-Version' => self::NOTION_API_VERSION])
                ->post("https://api.notion.com/v1/databases/{$databaseId}/query", $body);

            if (! $response->successful()) {
                Log::warning('NotionConnector: Database query failed', [
                    'database_id' => $databaseId,
                    'status' => $response->status(),
                ]);

                return $ingested;
            }

            $data = $response->json();
            $pages = $data['results'] ?? [];

            foreach ($pages as $page) {
                try {
                    $title = $this->extractTitle($page);
                    $content = $this->extractContent($page);
                    $pageUrl = $page['url'] ?? "https://notion.so/{$page['id']}";

                    $this->ingestAction->execute(
                        teamId: $teamId,
                        title: $title,
                        content: $content,
                        sourceUrl: $pageUrl,
                        sourceName: 'notion',
                    );
                    $ingested++;
                } catch (\Throwable $e) {
                    Log::error('NotionConnector: Error ingesting page', [
                        'page_id' => $page['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $cursor = $data['has_more'] ? ($data['next_cursor'] ?? null) : null;
        } while ($cursor);

        return $ingested;
    }

    private function extractTitle(array $page): string
    {
        $properties = $page['properties'] ?? [];

        foreach ($properties as $name => $property) {
            if (($property['type'] ?? '') === 'title') {
                $titleItems = $property['title'] ?? [];
                $text = implode('', array_map(fn ($t) => $t['plain_text'] ?? '', $titleItems));
                if ($text) {
                    return $text;
                }
            }
        }

        return $page['id'] ?? 'Untitled';
    }

    private function extractContent(array $page): string
    {
        $parts = [];

        $properties = $page['properties'] ?? [];
        foreach ($properties as $name => $property) {
            $type = $property['type'] ?? '';

            if ($type === 'rich_text') {
                $textItems = $property['rich_text'] ?? [];
                $text = implode('', array_map(fn ($t) => $t['plain_text'] ?? '', $textItems));
                if ($text) {
                    $parts[] = "{$name}: {$text}";
                }
            } elseif ($type === 'select') {
                $value = $property['select']['name'] ?? '';
                if ($value) {
                    $parts[] = "{$name}: {$value}";
                }
            } elseif ($type === 'multi_select') {
                $values = array_map(fn ($s) => $s['name'] ?? '', $property['multi_select'] ?? []);
                if ($values) {
                    $parts[] = "{$name}: ".implode(', ', $values);
                }
            } elseif ($type === 'status') {
                $value = $property['status']['name'] ?? '';
                if ($value) {
                    $parts[] = "{$name}: {$value}";
                }
            }
        }

        return implode("\n", $parts);
    }
}
