<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Integration\Services\WebclawResolver;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

class WebScrapingConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    public function getDriverName(): string
    {
        return 'webclaw_scrape';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'webclaw_scrape';
    }

    /**
     * Poll a URL via Webclaw and ingest the extracted content as a signal.
     *
     * Config expects: ['url' => string, 'format' => ?string, 'tags' => ?array]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $url = $config['url'] ?? null;
        if (! $url) {
            Log::warning('WebScrapingConnector: No URL provided', $config);

            return [];
        }

        $format = $config['format'] ?? 'markdown';
        $tags = $config['tags'] ?? ['webclaw'];
        $teamId = $config['_team_id'] ?? null;

        try {
            app(SsrfGuard::class)->assertPublicUrl($url);

            $cfg = WebclawResolver::forTeam($teamId);
            $response = $cfg['http']->post($cfg['url'].'/v1/scrape', ['url' => $url, 'format' => $format]);

            if (! $response->successful()) {
                Log::warning('WebScrapingConnector: Webclaw request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            $content = $data['content'] ?? '';
            $metadata = $data['metadata'] ?? [];

            $signal = $this->ingestAction->execute(
                sourceType: 'webclaw_scrape',
                sourceIdentifier: $url,
                payload: [
                    'url' => $url,
                    'title' => $metadata['title'] ?? $url,
                    'content' => $content,
                    'format' => $format,
                ],
                tags: $tags,
                teamId: $teamId,
            );

            return $signal ? [$signal] : [];
        } catch (\Throwable $e) {
            Log::error('WebScrapingConnector: Error scraping URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
