<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScreenpipeConnector implements InputConnectorInterface
{
    private const DEFAULT_BASE_URL = 'http://localhost:3030';

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Poll screenpipe's local REST API for new screen/audio content.
     *
     * Config expects:
     *   'base_url'      => string (default http://localhost:3030)
     *   'content_type'  => 'ocr'|'audio'|'all' (default 'all')
     *   'app_name'      => ?string (filter by app)
     *   'query'         => ?string (full-text search)
     *   'limit'         => int (default 20)
     *   'experiment_id' => ?string
     *   'tags'          => ?array
     *   '_last_timestamp' => ?string (cursor for dedup — managed by getUpdatedConfig)
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $baseUrl = rtrim($config['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $experimentId = $config['experiment_id'] ?? null;
        $tags = $config['tags'] ?? ['screenpipe'];
        $teamId = $config['_team_id'] ?? null;

        // Screenpipe is a local desktop app — only allow loopback addresses to prevent SSRF.
        if (! $this->isLoopbackUrl($baseUrl)) {
            Log::warning('ScreenpipeConnector: Rejected non-loopback URL', ['base_url' => $baseUrl]);

            return [];
        }

        try {
            $params = array_filter([
                'content_type' => $config['content_type'] ?? 'all',
                'app_name' => $config['app_name'] ?? null,
                'q' => $config['query'] ?? null,
                'limit' => min((int) ($config['limit'] ?? 20), 100),
                'start_time' => $config['_last_timestamp'] ?? now()->subMinutes(15)->toIso8601String(),
            ]);

            $response = Http::timeout(15)
                ->get("{$baseUrl}/search", $params);

            if (! $response->successful()) {
                Log::warning('ScreenpipeConnector: API returned error', [
                    'status' => $response->status(),
                    'base_url' => $baseUrl,
                ]);

                return [];
            }

            $data = $response->json();
            $items = $data['data'] ?? [];

            return $this->ingestItems($items, $baseUrl, $experimentId, $tags, $teamId);
        } catch (\Throwable $e) {
            Log::error('ScreenpipeConnector: Error polling', [
                'base_url' => $baseUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'screenpipe';
    }

    public function getDriverName(): string
    {
        return 'screenpipe';
    }

    /**
     * Return updated config with cursor for next poll.
     */
    public function getUpdatedConfig(array $config, array $signals): array
    {
        if (! empty($signals)) {
            $config['_last_timestamp'] = now()->toIso8601String();
        }

        return $config;
    }

    /**
     * @return Signal[]
     */
    private function ingestItems(array $items, string $baseUrl, ?string $experimentId, array $tags, ?string $teamId): array
    {
        $signals = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? 'unknown';
            $content = $item['content'] ?? [];

            if ($type === 'OCR') {
                $payload = $this->parseOcrItem($content);
            } elseif ($type === 'Audio') {
                $payload = $this->parseAudioItem($content);
            } else {
                continue;
            }

            if (empty($payload['text'])) {
                continue;
            }

            // Use timestamp + app + window as native ID for dedup
            $nativeId = md5(($payload['timestamp'] ?? '').($payload['app_name'] ?? '').($payload['text'] ?? ''));

            $signal = $this->ingestAction->execute(
                sourceType: 'screenpipe',
                sourceIdentifier: $baseUrl,
                payload: $payload,
                tags: array_merge($tags, [$type === 'Audio' ? 'audio' : 'screen']),
                experimentId: $experimentId,
                sourceNativeId: "screenpipe:{$nativeId}",
                teamId: $teamId,
            );

            if ($signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Only allow loopback URLs (localhost, 127.0.0.1, [::1]).
     */
    private function isLoopbackUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (! $parsed || empty($parsed['host'])) {
            return false;
        }

        $host = strtolower($parsed['host']);

        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private function parseOcrItem(array $content): array
    {
        $frame = $content['frame'] ?? $content;

        return array_filter([
            'type' => 'ocr',
            'text' => mb_substr((string) ($frame['text'] ?? $content['text'] ?? ''), 0, 5000),
            'app_name' => (string) ($frame['app_name'] ?? $content['app_name'] ?? ''),
            'window_name' => (string) ($frame['window_name'] ?? $content['window_name'] ?? ''),
            'timestamp' => (string) ($frame['timestamp'] ?? $content['timestamp'] ?? ''),
        ]);
    }

    private function parseAudioItem(array $content): array
    {
        return array_filter([
            'type' => 'audio',
            'text' => mb_substr((string) ($content['transcription'] ?? ''), 0, 5000),
            'device_name' => (string) ($content['device_name'] ?? ''),
            'speaker' => (string) ($content['speaker'] ?? ''),
            'timestamp' => (string) ($content['timestamp'] ?? ''),
            'duration_secs' => $content['duration_secs'] ?? null,
        ]);
    }
}
