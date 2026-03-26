<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Searxng search connector.
 *
 * Queries a self-hosted Searxng instance for web search results.
 * The URL is always operator-configured (GlobalSetting or config), never user-supplied.
 *
 * Note: assertPublicUrl() is skipped when $skipSsrfCheck=true because Searxng
 * may run as an internal Docker service (RFC 1918) that is operator-trusted.
 * Never pass skipSsrfCheck=true with user-supplied URLs.
 */
class SearxngConnector
{
    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * Search Searxng and return an array of result items.
     *
     * @param  string  $query  Search query
     * @param  array{engines?: string[], language?: string, time_range?: string, safesearch?: int, limit?: int}  $options
     * @param  bool  $skipSsrfCheck  Set true when URL is operator-configured (e.g. internal Docker hostname)
     * @return array<int, array{title: string, url: string, content: string, engine: string}>
     */
    public function search(string $query, array $options = [], bool $skipSsrfCheck = false): array
    {
        $url = $this->resolveUrl();
        if (! $url) {
            return [];
        }

        if (! $skipSsrfCheck) {
            $this->ssrfGuard->assertPublicUrl($url);
        }

        try {
            $params = array_filter([
                'q' => $query,
                'format' => 'json',
                'engines' => isset($options['engines']) ? implode(',', $options['engines']) : null,
                'language' => $options['language'] ?? 'en',
                'time_range' => $options['time_range'] ?? null,
                'safesearch' => $options['safesearch'] ?? 1,
            ], fn ($v) => $v !== null && $v !== '');

            $response = Http::timeout(15)->get(rtrim($url, '/').'/search', $params);

            if (! $response->successful()) {
                Log::warning('SearxngConnector: search failed', [
                    'status' => $response->status(),
                    'query' => $query,
                ]);

                return [];
            }

            $data = $response->json();
            $results = $data['results'] ?? [];
            $limit = min((int) ($options['limit'] ?? 10), 50);

            return array_map(fn (array $item) => [
                'title' => $item['title'] ?? '',
                'url' => $item['url'] ?? '',
                'content' => $item['content'] ?? '',
                'engine' => $item['engine'] ?? '',
            ], array_slice($results, 0, $limit));
        } catch (\Throwable $e) {
            Log::error('SearxngConnector: error during search', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check whether a Searxng instance is configured and reachable.
     */
    public function isConfigured(): bool
    {
        return (bool) $this->resolveUrl();
    }

    private function resolveUrl(): ?string
    {
        return config('services.searxng.url') ?: null;
    }
}
