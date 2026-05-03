<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Integration\Services\WebclawResolver;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use App\Mcp\Contracts\AutoRegistersAsMcpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;

class WebScrapingConnector implements AutoRegistersAsMcpTool, InputConnectorInterface
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

    // -------------------------------------------------------------------------
    // AutoRegistersAsMcpTool — exposes this connector as MCP tool "signal.webclaw.scrape"
    // -------------------------------------------------------------------------

    public function mcpName(): string
    {
        return 'signal.webclaw.scrape';
    }

    public function mcpDescription(): string
    {
        return 'Scrape a single URL via Webclaw and ingest the extracted content as a Signal in the current team. Returns markdown by default; use format=html for raw HTML.';
    }

    public function mcpInputSchema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->required()
                ->description('URL to scrape — must be publicly reachable (SSRF-guarded).'),
            'format' => $schema->string()
                ->description('Output format: markdown (default) or html.'),
            'tags' => $schema->array()
                ->description('Optional tags applied to the signal.'),
        ];
    }

    public function mcpInvoke(array $params, string $teamId): array
    {
        // WebScrapingConnector reads team id from `_team_id` in config.
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
