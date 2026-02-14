<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RssConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Poll an RSS feed and ingest new items as signals.
     *
     * Config expects: ['url' => string, 'experiment_id' => ?string, 'tags' => ?array]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $url = $config['url'] ?? null;
        if (! $url) {
            Log::warning('RssConnector: No URL provided', $config);

            return [];
        }

        $experimentId = $config['experiment_id'] ?? null;
        $tags = $config['tags'] ?? ['rss'];

        try {
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::warning('RssConnector: Failed to fetch feed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $xml = @simplexml_load_string($response->body());
            if (! $xml) {
                Log::warning('RssConnector: Invalid XML', ['url' => $url]);

                return [];
            }

            return $this->parseAndIngest($xml, $url, $experimentId, $tags);
        } catch (\Throwable $e) {
            Log::error('RssConnector: Error polling feed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'rss';
    }

    private function parseAndIngest(\SimpleXMLElement $xml, string $url, ?string $experimentId, array $tags): array
    {
        $signals = [];

        // Support both RSS 2.0 (channel/item) and Atom (entry)
        $items = $xml->channel->item ?? $xml->entry ?? [];

        foreach ($items as $item) {
            $title = (string) ($item->title ?? '');
            $link = (string) ($item->link ?? $item->link['href'] ?? '');
            $description = (string) ($item->description ?? $item->summary ?? $item->content ?? '');
            $pubDate = (string) ($item->pubDate ?? $item->published ?? $item->updated ?? '');

            $payload = array_filter([
                'title' => $title,
                'link' => $link,
                'description' => strip_tags($description),
                'pub_date' => $pubDate,
            ]);

            if (empty($payload['title']) && empty($payload['link'])) {
                continue;
            }

            $signal = $this->ingestAction->execute(
                sourceType: 'rss',
                sourceIdentifier: $url,
                payload: $payload,
                tags: $tags,
                experimentId: $experimentId,
            );

            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }
}
