<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Connectors\SearxngConnector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool for web search via a self-hosted Searxng instance.
 *
 * Requires SEARXNG_URL to be configured. The URL is always operator-set,
 * never user-supplied, so the SSRF check is skipped for internal Docker URLs.
 */
#[IsReadOnly]
class SearxngSearchTool extends Tool
{
    protected string $name = 'searxng_search';

    protected string $description = 'Search the web using the platform\'s self-hosted Searxng instance. Returns titles, URLs, and content snippets from multiple search engines. Use for current events, research, and finding external information.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query')
                ->required(),
            'engines' => $schema->array()
                ->description('Search engines to use (e.g. ["google", "bing", "duckduckgo"]). Omit to use Searxng defaults.')
                ->items($schema->string()),
            'language' => $schema->string()
                ->description('Language code for results (default: en)')
                ->default('en'),
            'time_range' => $schema->string()
                ->description('Filter by time range: day | week | month | year')
                ->enum(['day', 'week', 'month', 'year']),
            'limit' => $schema->integer()
                ->description('Number of results to return (default: 10, max: 50)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => 'required|string|max:500',
            'engines' => 'nullable|array',
            'engines.*' => 'string|max:50',
            'language' => 'nullable|string|max:10',
            'time_range' => 'nullable|string|in:day,week,month,year',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        /** @var SearxngConnector $connector */
        $connector = app(SearxngConnector::class);

        if (! $connector->isConfigured()) {
            return Response::error('Searxng is not configured on this instance. Set SEARXNG_URL in your environment.');
        }

        $results = $connector->search(
            query: $validated['query'],
            options: array_filter([
                'engines' => $validated['engines'] ?? null,
                'language' => $validated['language'] ?? 'en',
                'time_range' => $validated['time_range'] ?? null,
                'limit' => isset($validated['limit']) ? (int) $validated['limit'] : 10,
            ], fn ($v) => $v !== null),
            skipSsrfCheck: true, // URL is always operator-configured, never user-supplied
        );

        return Response::text(json_encode([
            'results' => $results,
            'count' => count($results),
            'query' => $validated['query'],
        ]));
    }
}
