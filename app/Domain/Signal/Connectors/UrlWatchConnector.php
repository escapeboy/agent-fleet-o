<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Integration\Services\WebclawResolver;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UrlWatchConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    public function getDriverName(): string
    {
        return 'url_watch';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'url_watch';
    }

    /**
     * Diff a URL against its last snapshot and ingest a signal if content changed.
     *
     * Config expects: ['url' => string, 'tags' => ?array, 'connector_id' => ?string]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $url = $config['url'] ?? null;
        if (! $url) {
            Log::warning('UrlWatchConnector: No URL provided', $config);

            return [];
        }

        $connectorId = $config['connector_id'] ?? md5($url);
        $tags = $config['tags'] ?? ['url_watch'];
        $teamId = $config['_team_id'] ?? null;

        try {
            app(SsrfGuard::class)->assertPublicUrl($url);

            $cacheKey = "url_watch_snapshot:{$connectorId}:{$url}";
            $lastSnapshot = Cache::get($cacheKey);

            $payload = ['url' => $url];
            if ($lastSnapshot !== null) {
                $payload['snapshot'] = $lastSnapshot;
            }

            $cfg = WebclawResolver::forTeam($teamId);
            $response = $cfg['http']->post($cfg['url'].'/v1/diff', $payload);

            if (! $response->successful()) {
                Log::warning('UrlWatchConnector: Webclaw diff request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            $newSnapshot = $data['snapshot'] ?? '';

            Cache::put($cacheKey, $newSnapshot, now()->addDays(30));

            if (! ($data['changed'] ?? false)) {
                return [];
            }

            $signal = $this->ingestAction->execute(
                sourceType: 'url_watch',
                sourceIdentifier: $url,
                payload: [
                    'url' => $url,
                    'diff' => $data['diff'] ?? '',
                    'snapshot' => $newSnapshot,
                ],
                tags: $tags,
                teamId: $teamId,
            );

            return $signal ? [$signal] : [];
        } catch (\Throwable $e) {
            Log::error('UrlWatchConnector: Error watching URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
