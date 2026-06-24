<?php

namespace App\Mcp\Tools\Search;

use App\Domain\Search\Contracts\WebSearchProviderInterface;
use App\Domain\Search\Exceptions\WebSearchUnavailableException;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Provider-agnostic web search. Resolves the configured provider via the
 * WebSearchProviderInterface seam (WEB_SEARCH_DRIVER: searxng default, serper opt-in)
 * and returns normalized title/url/snippet results. Stateless — no team scope.
 */
#[IsReadOnly]
#[AssistantTool('read')]
class WebSearchTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'web_search';

    protected string $description = 'Search the web via the platform-configured provider (self-hosted Searxng by default; Serper optional). Returns titles, URLs, and snippets. Provider chosen by operator config, not by the agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query string')
                ->required(),
            'max_results' => $schema->integer()
                ->description('Maximum number of results (default 5, max 20)')
                ->default(5),
        ];
    }

    public function handle(Request $request): Response
    {
        // Paid-plan gate when PlanEnforcer is available (cloud); skipped in community edition.
        if (app()->bound('App\Domain\Shared\Services\PlanEnforcer')) {
            try {
                if (! app('App\Domain\Shared\Services\PlanEnforcer')->hasFeature('searxng_access')) {
                    return $this->failedPreconditionError('Web search requires a paid plan. Upgrade at /billing.');
                }
            } catch (\Throwable) {
                // base/community edition has no PlanEnforcer — allow.
            }
        }

        $query = (string) $request->get('query');

        if ($query === '') {
            return $this->invalidArgumentError('query is required.');
        }

        $maxResults = min((int) ($request->get('max_results') ?? 5), 20);

        try {
            $provider = app(WebSearchProviderInterface::class);
            $results = $provider->search($query, ['max_results' => $maxResults]);
        } catch (WebSearchUnavailableException $e) {
            return $this->failedPreconditionError($e->getMessage());
        }

        return Response::text(json_encode([
            'provider' => $provider->name(),
            'query' => $query,
            'results' => $results,
            'count' => count($results),
        ]));
    }
}
