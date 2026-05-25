<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Shared\Contracts\AutoRegistersAsMcpTool;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HackerNewsConnector implements AutoRegistersAsMcpTool, InputConnectorInterface
{
    private const API_BASE = 'https://hacker-news.firebaseio.com/v0';

    /** feed alias => Firebase list endpoint */
    private const FEEDS = [
        'top' => 'topstories',
        'best' => 'beststories',
        'new' => 'newstories',
        'ask' => 'askstories',
        'show' => 'showstories',
    ];

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Poll a Hacker News story feed and ingest each story as a signal.
     *
     * Config: ['feed' => top|best|new|ask|show, 'limit' => int, 'min_score' => int,
     *          'tags' => ?array, 'experiment_id' => ?string]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $feed = (string) ($config['feed'] ?? 'top');
        if (! isset(self::FEEDS[$feed])) {
            Log::warning('HackerNewsConnector: unknown feed', ['feed' => $feed]);

            return [];
        }

        $limit = min(max((int) ($config['limit'] ?? 15), 1), 50);
        $minScore = max((int) ($config['min_score'] ?? 0), 0);
        $tags = array_values(array_unique(array_merge(['hacker_news', $feed], (array) ($config['tags'] ?? []))));
        $teamId = $config['_team_id'] ?? null;
        $experimentId = $config['experiment_id'] ?? null;

        try {
            $listResponse = Http::timeout(30)->get(self::API_BASE."/{$feed}stories.json");
            if (! $listResponse->successful()) {
                Log::warning('HackerNewsConnector: failed to fetch list', [
                    'feed' => $feed,
                    'status' => $listResponse->status(),
                ]);

                return [];
            }

            $ids = array_slice((array) $listResponse->json(), 0, $limit);
            $signals = [];

            foreach ($ids as $id) {
                $itemResponse = Http::timeout(30)->get(self::API_BASE."/item/{$id}.json");
                if (! $itemResponse->successful()) {
                    continue;
                }

                $item = $itemResponse->json();
                if (! is_array($item) || ($item['type'] ?? '') !== 'story') {
                    continue;
                }

                if ((int) ($item['score'] ?? 0) < $minScore) {
                    continue;
                }

                $signal = $this->ingestAction->execute(
                    sourceType: 'hacker_news',
                    sourceIdentifier: 'hacker_news:'.$feed,
                    payload: array_filter([
                        'title' => $item['title'] ?? '',
                        'url' => $item['url'] ?? null,
                        'text' => isset($item['text']) ? strip_tags((string) $item['text']) : null,
                        'score' => $item['score'] ?? 0,
                        'author' => $item['by'] ?? null,
                        'comments' => $item['descendants'] ?? 0,
                        'hn_url' => 'https://news.ycombinator.com/item?id='.$id,
                    ], fn ($v) => $v !== null && $v !== ''),
                    tags: $tags,
                    experimentId: $experimentId,
                    sourceNativeId: (string) $id,
                    teamId: $teamId,
                );

                if ($signal) {
                    $signals[] = $signal;
                }
            }

            return $signals;
        } catch (\Throwable $e) {
            Log::error('HackerNewsConnector: error polling feed', [
                'feed' => $feed,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'hacker_news';
    }

    public function getDriverName(): string
    {
        return 'hacker_news';
    }

    // -------------------------------------------------------------------------
    // AutoRegistersAsMcpTool — exposes this connector as MCP tool "signal.hacker_news.poll"
    // -------------------------------------------------------------------------

    public function mcpName(): string
    {
        return 'signal.hacker_news.poll';
    }

    public function mcpDescription(): string
    {
        return 'Poll a Hacker News feed (top/best/new/ask/show) once and ingest each story as a Signal in the current team. For recurring polling configure a Signal Connector binding instead.';
    }

    public function mcpInputSchema(JsonSchema $schema): array
    {
        return [
            'feed' => $schema->string()
                ->description('Which feed to poll: top, best, new, ask, or show. Defaults to top.'),
            'limit' => $schema->integer()
                ->description('Maximum number of stories to fetch (1-50, default 15).'),
            'min_score' => $schema->integer()
                ->description('Only ingest stories with at least this score. Default 0.'),
            'tags' => $schema->array()
                ->description('Optional tags applied to ingested signals.'),
            'experiment_id' => $schema->string()
                ->description('Optional experiment UUID to associate signals with.'),
        ];
    }

    public function mcpInvoke(array $params, string $teamId): array
    {
        $params['_team_id'] = $teamId;

        $signals = $this->poll($params);

        return [
            'count' => count($signals),
            'signal_ids' => array_map(fn (Signal $s) => $s->id, $signals),
        ];
    }

    public function mcpAnnotations(): array
    {
        return ['read_only' => false, 'idempotent' => false, 'assistant_tool' => 'write'];
    }
}
