<?php

namespace App\Domain\Search\Providers;

use App\Domain\Search\Contracts\WebSearchProviderInterface;
use App\Domain\Search\Exceptions\WebSearchUnavailableException;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Http;

/**
 * Opt-in web-search provider via the Serper.dev Google API (BYOK key). Proves the
 * seam is pluggable; selected with WEB_SEARCH_DRIVER=serper + a configured key.
 */
class SerperWebSearchProvider implements WebSearchProviderInterface
{
    public function name(): string
    {
        return 'serper';
    }

    public function search(string $query, array $options = []): array
    {
        $key = GlobalSetting::get('serper_api_key') ?? config('web_search.providers.serper.key');

        if (! $key) {
            throw new WebSearchUnavailableException('No Serper API key configured (SERPER_API_KEY / serper_api_key).');
        }

        $max = min((int) ($options['max_results'] ?? 5), 20);

        $response = Http::withHeaders([
            'X-API-KEY' => $key,
            'Content-Type' => 'application/json',
        ])->timeout(15)->post('https://google.serper.dev/search', [
            'q' => $query,
            'num' => $max,
        ]);

        if (! $response->successful()) {
            throw new WebSearchUnavailableException('Serper request failed: HTTP '.$response->status());
        }

        $organic = $response->json('organic') ?? [];

        return array_map(fn (array $r): array => [
            'title' => $r['title'] ?? '',
            'url' => $r['link'] ?? '',
            'snippet' => $r['snippet'] ?? '',
        ], array_slice($organic, 0, $max));
    }
}
