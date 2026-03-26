<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Connectors\SearxngConnector;
use App\Models\GlobalSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool for synchronous web search via a self-hosted Searxng instance.
 *
 * Resolves the Searxng URL from (in priority order):
 *   1. The `url` parameter passed directly by the agent
 *   2. GlobalSetting::get('searxng_url')
 *   3. env('SEARXNG_URL')
 *
 * @see https://github.com/searxng/searxng
 */
#[IsReadOnly]
class SearxngSearchTool extends Tool
{
    protected string $name = 'searxng_search';

    protected string $description = 'Search the web using a self-hosted Searxng meta-search instance. Returns titles, URLs, and snippets. Requires a Searxng instance configured via SEARXNG_URL env var or platform settings.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query string')
                ->required(),
            'categories' => $schema->array()
                ->description("Searxng categories to search (default: ['general']). Options: general, images, news, science, social_media, videos")
                ->items($schema->string()),
            'max_results' => $schema->integer()
                ->description('Maximum number of results to return (default: 5, max: 20)')
                ->default(5),
            'url' => $schema->string()
                ->description('Override Searxng instance URL (e.g. http://searxng:8888). Uses SEARXNG_URL env var by default.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = $request->get('query');

        if (empty($query)) {
            return Response::error('query is required.');
        }

        $instanceUrl = $request->get('url')
            ?? GlobalSetting::get('searxng_url')
            ?? config('services.searxng.url')
            ?? env('SEARXNG_URL');

        if (! $instanceUrl) {
            return Response::error(
                'No Searxng instance configured. Set SEARXNG_URL in your environment or configure it in platform settings (searxng_url).'
            );
        }

        $maxResults = min((int) ($request->get('max_results') ?? 5), 20);
        $categories = $request->get('categories') ?? ['general'];

        $connector = new SearxngConnector(
            app(IngestSignalAction::class),
            app(SsrfGuard::class),
        );

        $results = $connector->search($instanceUrl, $query, [
            'categories' => $categories,
            'max_results' => $maxResults,
        ]);

        if (empty($results)) {
            return Response::text(json_encode([
                'query' => $query,
                'results' => [],
                'message' => 'No results found.',
            ]));
        }

        return Response::text(json_encode([
            'query' => $query,
            'results' => $results,
            'count' => count($results),
        ]));
    }
}
