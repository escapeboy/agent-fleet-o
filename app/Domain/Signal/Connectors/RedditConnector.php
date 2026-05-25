<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Shared\Contracts\AutoRegistersAsMcpTool;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RedditConnector implements AutoRegistersAsMcpTool, InputConnectorInterface
{
    private const SORTS = ['hot', 'new', 'top', 'rising'];

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Poll a public subreddit and ingest each post as a signal.
     *
     * Config: ['subreddit' => string, 'sort' => hot|new|top|rising, 'limit' => int,
     *          'min_score' => int, 'time' => hour|day|week|month|year|all,
     *          'tags' => ?array, 'experiment_id' => ?string]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $subreddit = trim((string) ($config['subreddit'] ?? ''));
        // Reddit subreddit names are alphanumeric + underscore — reject anything else to
        // prevent path traversal into other Reddit endpoints.
        if ($subreddit === '' || ! preg_match('/^[A-Za-z0-9_]{1,50}$/', $subreddit)) {
            Log::warning('RedditConnector: invalid or missing subreddit', ['subreddit' => $subreddit]);

            return [];
        }

        $sort = in_array($config['sort'] ?? 'hot', self::SORTS, true) ? $config['sort'] : 'hot';
        $limit = min(max((int) ($config['limit'] ?? 15), 1), 100);
        $minScore = max((int) ($config['min_score'] ?? 0), 0);
        $tags = array_values(array_unique(array_merge(['reddit', $subreddit], (array) ($config['tags'] ?? []))));
        $teamId = $config['_team_id'] ?? null;
        $experimentId = $config['experiment_id'] ?? null;

        $query = ['limit' => $limit];
        if ($sort === 'top' && isset($config['time'])) {
            $query['t'] = $config['time'];
        }

        try {
            // Reddit blocks generic/empty User-Agents with HTTP 429; a descriptive UA is required.
            $response = Http::withHeaders(['User-Agent' => 'FleetQ-SignalConnector/1.0'])
                ->timeout(30)
                ->get("https://www.reddit.com/r/{$subreddit}/{$sort}.json", $query);

            if (! $response->successful()) {
                Log::warning('RedditConnector: request failed', [
                    'subreddit' => $subreddit,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $children = $response->json('data.children') ?? [];
            $signals = [];

            foreach ($children as $child) {
                if (($child['kind'] ?? '') !== 't3') {
                    continue;
                }

                $post = $child['data'] ?? [];
                if ((int) ($post['score'] ?? 0) < $minScore) {
                    continue;
                }

                $permalink = isset($post['permalink']) ? 'https://www.reddit.com'.$post['permalink'] : null;

                $signal = $this->ingestAction->execute(
                    sourceType: 'reddit',
                    sourceIdentifier: 'r/'.$subreddit,
                    payload: array_filter([
                        'title' => $post['title'] ?? '',
                        'url' => $post['url'] ?? null,
                        'permalink' => $permalink,
                        'text' => isset($post['selftext']) ? strip_tags((string) $post['selftext']) : null,
                        'score' => $post['score'] ?? 0,
                        'author' => $post['author'] ?? null,
                        'comments' => $post['num_comments'] ?? 0,
                        'subreddit' => $subreddit,
                    ], fn ($v) => $v !== null && $v !== ''),
                    tags: $tags,
                    experimentId: $experimentId,
                    sourceNativeId: (string) ($post['name'] ?? $post['id'] ?? ''),
                    teamId: $teamId,
                );

                if ($signal) {
                    $signals[] = $signal;
                }
            }

            return $signals;
        } catch (\Throwable $e) {
            Log::error('RedditConnector: error polling subreddit', [
                'subreddit' => $subreddit,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'reddit';
    }

    public function getDriverName(): string
    {
        return 'reddit';
    }

    // -------------------------------------------------------------------------
    // AutoRegistersAsMcpTool — exposes this connector as MCP tool "signal.reddit.poll"
    // -------------------------------------------------------------------------

    public function mcpName(): string
    {
        return 'signal.reddit.poll';
    }

    public function mcpDescription(): string
    {
        return 'Poll a public subreddit (hot/new/top/rising) once and ingest each post as a Signal in the current team. For recurring polling configure a Signal Connector binding instead.';
    }

    public function mcpInputSchema(JsonSchema $schema): array
    {
        return [
            'subreddit' => $schema->string()->required()
                ->description('Subreddit name without the r/ prefix (alphanumeric and underscore only).'),
            'sort' => $schema->string()
                ->description('Listing sort: hot, new, top, or rising. Defaults to hot.'),
            'limit' => $schema->integer()
                ->description('Maximum number of posts to fetch (1-100, default 15).'),
            'min_score' => $schema->integer()
                ->description('Only ingest posts with at least this score. Default 0.'),
            'time' => $schema->string()
                ->description('Time window for the "top" sort: hour, day, week, month, year, or all.'),
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
