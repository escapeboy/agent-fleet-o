<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Searxng self-hosted meta-search connector.
 *
 * Polls a Searxng instance for search results and ingests them as signals.
 * Searxng is a free, privacy-respecting meta-search engine that can be self-hosted
 * via Docker at zero per-query cost.
 *
 * Config keys:
 *   url         — Searxng instance base URL, e.g. http://searxng:8888 (required)
 *   query       — search query string (required for poll mode)
 *   categories  — array of Searxng categories (default: ['general'])
 *   engines     — comma-separated engine names to restrict search (default: '' = all active)
 *   language    — language code, e.g. 'en' (default: 'en')
 *   max_results — maximum results to ingest per poll cycle (default: 10)
 *   timeout     — HTTP request timeout in seconds (default: 15)
 *
 * @see https://github.com/searxng/searxng
 */
class SearxngConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    public function getDriverName(): string
    {
        return 'searxng';
    }

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $url = rtrim($config['url'] ?? '', '/');
        $query = $config['query'] ?? null;

        if (! $url || ! $query) {
            Log::warning('SearxngConnector: url and query are required', $config);

            return [];
        }

        $categories = implode(',', (array) ($config['categories'] ?? ['general']));
        $engines = $config['engines'] ?? '';
        $language = $config['language'] ?? 'en';
        $maxResults = min((int) ($config['max_results'] ?? 10), 100);
        $timeout = min((int) ($config['timeout'] ?? 15), 30);

        try {
            $this->ssrfGuard->assertPublicUrl($url);

            $params = array_filter([
                'q' => $query,
                'format' => 'json',
                'categories' => $categories,
                'engines' => $engines,
                'language' => $language,
            ]);

            $response = Http::timeout($timeout)->get("{$url}/search", $params);

            if (! $response->successful()) {
                Log::warning('SearxngConnector: request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            if (empty($results)) {
                return [];
            }

            return $this->ingestResults(
                array_slice($results, 0, $maxResults),
                $query,
                $categories,
                $config,
            );
        } catch (\Throwable $e) {
            Log::warning('SearxngConnector: poll failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Execute a one-off synchronous search and return raw results (no signal ingestion).
     *
     * Used by the SearxngSearchTool for agent-driven searches.
     *
     * @return array<int, array{title: string, url: string, content: string, score: float, engine: string}>
     */
    public function search(string $instanceUrl, string $query, array $options = []): array
    {
        $url = rtrim($instanceUrl, '/');
        $categories = implode(',', (array) ($options['categories'] ?? ['general']));
        $engines = $options['engines'] ?? '';
        $language = $options['language'] ?? 'en';
        $maxResults = min((int) ($options['max_results'] ?? 5), 20);
        $timeout = min((int) ($options['timeout'] ?? 15), 30);

        try {
            $this->ssrfGuard->assertPublicUrl($url);

            $params = array_filter([
                'q' => $query,
                'format' => 'json',
                'categories' => $categories,
                'engines' => $engines,
                'language' => $language,
            ]);

            $response = Http::timeout($timeout)->get("{$url}/search", $params);

            if (! $response->successful()) {
                return [];
            }

            $results = $response->json('results', []);

            return array_map(fn (array $r) => [
                'title' => $r['title'] ?? '',
                'url' => $r['url'] ?? '',
                'content' => $r['content'] ?? '',
                'score' => (float) ($r['score'] ?? 0),
                'engine' => $r['engine'] ?? '',
            ], array_slice($results, 0, $maxResults));
        } catch (\Throwable $e) {
            Log::warning('SearxngConnector: search failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return Signal[]
     */
    private function ingestResults(array $results, string $query, string $categories, array $config): array
    {
        $signals = [];
        $tags = array_merge(
            ['search', 'searxng'],
            array_filter(explode(',', $categories)),
        );

        foreach ($results as $result) {
            $title = $result['title'] ?? '';
            $resultUrl = $result['url'] ?? '';
            $content = $result['content'] ?? '';

            if (empty($resultUrl)) {
                continue;
            }

            $body = trim($title ? "{$title}\n\n{$content}" : $content) ?: $resultUrl;

            try {
                $ingested = $this->ingestAction->execute(
                    sourceType: 'searxng',
                    sourceIdentifier: $resultUrl,
                    payload: [
                        'url' => $resultUrl,
                        'title' => $title,
                        'body' => $body,
                        'engine' => $result['engine'] ?? '',
                        'score' => $result['score'] ?? null,
                        'query' => $query,
                        'categories' => $categories,
                    ],
                    tags: $tags,
                    experimentId: $config['experiment_id'] ?? null,
                    teamId: $config['team_id'] ?? null,
                );

                if ($ingested) {
                    $signals[] = $ingested;
                }
            } catch (\Throwable $e) {
                Log::warning('SearxngConnector: failed to ingest result', [
                    'url' => $resultUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $signals;
    }
}
