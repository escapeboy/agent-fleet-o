<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class WebclawCrawlTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'webclaw_crawl';

    protected string $description = 'Crawl a website (BFS) and ingest each page as a signal';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Starting URL for the BFS crawl')
                ->required(),
            'max_pages' => $schema->integer()
                ->description('Maximum number of pages to crawl (default: 10, max: 50)')
                ->default(10),
            'format' => $schema->string()
                ->description('Output format: markdown, text, or json (default: markdown)')
                ->default('markdown'),
            'tags' => $schema->array()
                ->description('Tags to attach to each ingested signal')
                ->items($schema->string()),
        ];
    }

    public function handle(Request $request): Response
    {
        $url = $request->get('url');
        if (empty($url)) {
            return $this->invalidArgumentError('url is required.');
        }

        $maxPages = min((int) ($request->get('max_pages') ?? 10), 50);
        $format = $request->get('format') ?? 'markdown';
        $tags = $request->get('tags') ?? ['webclaw', 'crawl'];
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        try {
            app(SsrfGuard::class)->assertPublicUrl($url);

            $response = Http::timeout(120)->post(
                config('services.webclaw.url', env('WEBCLAW_URL', 'http://webclaw:3000')).'/v1/crawl',
                ['url' => $url, 'max_pages' => $maxPages, 'format' => $format],
            );

            if (! $response->successful()) {
                Log::warning('WebclawCrawlTool: Webclaw crawl request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return $this->unavailableError('Webclaw crawl request failed with status '.$response->status().'.');
            }

            $pages = $response->json('pages') ?? [];
            $ingestAction = app(IngestSignalAction::class);
            $ingested = 0;

            foreach ($pages as $page) {
                $pageUrl = $page['url'] ?? '';
                $content = $page['content'] ?? '';
                $metadata = $page['metadata'] ?? [];

                if (empty($pageUrl)) {
                    continue;
                }

                $signal = $ingestAction->execute(
                    sourceType: 'webclaw_scrape',
                    sourceIdentifier: $pageUrl,
                    payload: [
                        'url' => $pageUrl,
                        'title' => $metadata['title'] ?? $pageUrl,
                        'content' => $content,
                        'format' => $format,
                    ],
                    tags: $tags,
                    teamId: $teamId,
                );

                if ($signal) {
                    $ingested++;
                }
            }

            return Response::text(json_encode([
                'success' => true,
                'pages_found' => count($pages),
                'signals_ingested' => $ingested,
                'url' => $url,
            ]));
        } catch (\Throwable $e) {
            Log::error('WebclawCrawlTool: Error crawling URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return $this->internalError('Crawl failed: '.$e->getMessage());
        }
    }
}
