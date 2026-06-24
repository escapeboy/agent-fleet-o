<?php

namespace App\Domain\Search\Providers;

use App\Domain\Search\Contracts\WebSearchProviderInterface;
use App\Domain\Search\Exceptions\WebSearchUnavailableException;
use App\Domain\Signal\Connectors\SearxngConnector;
use App\Models\GlobalSetting;

/**
 * Default web-search provider: a self-hosted SearXNG meta-search instance.
 * Wraps the existing SearxngConnector. The instance URL is operator-configured
 * only (GlobalSetting → config), never agent-supplied, to prevent SSRF.
 */
class SearxngWebSearchProvider implements WebSearchProviderInterface
{
    public function __construct(
        private readonly SearxngConnector $connector,
    ) {}

    public function name(): string
    {
        return 'searxng';
    }

    public function search(string $query, array $options = []): array
    {
        $url = GlobalSetting::get('searxng_url') ?? config('web_search.providers.searxng.url');

        if (! $url) {
            throw new WebSearchUnavailableException(
                'No Searxng instance configured. Set SEARXNG_URL or the searxng_url platform setting.',
            );
        }

        $raw = $this->connector->search($url, $query, [
            'categories' => $options['categories'] ?? ['general'],
            'max_results' => min((int) ($options['max_results'] ?? 5), 20),
        ]);

        return array_map(fn (array $r): array => [
            'title' => $r['title'],
            'url' => $r['url'],
            'snippet' => $r['content'],
        ], $raw);
    }
}
