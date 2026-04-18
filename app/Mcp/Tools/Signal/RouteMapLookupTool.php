<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\RouteMap;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class RouteMapLookupTool extends Tool
{
    protected string $name = 'route_map_lookup';

    protected string $description = 'Look up the controller, middleware, and Livewire component for a given URL and project. Returns the route → file mapping registered for this team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('Project key (e.g. "chatbot", "client-platform")'),
            'url' => $schema->string()
                ->description('Full URL or path to look up (e.g. "/settings/profile" or "https://app.com/settings/profile")'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $project = $request->get('project');
        $url = $request->get('url');

        $routeMap = RouteMap::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('project', $project)
            ->first();

        if (! $routeMap) {
            return Response::text(json_encode(['error' => 'No route map registered for this project']));
        }

        $path = parse_url($url, PHP_URL_PATH) ?? $url;

        foreach ($routeMap->routes ?? [] as $route) {
            $uri = $route['uri'] ?? '';

            if ($this->uriMatches($uri, $path)) {
                return Response::text(json_encode([
                    'project' => $project,
                    'url' => $url,
                    'route' => $route,
                ]));
            }
        }

        return Response::text(json_encode(['error' => 'No matching route found', 'path' => $path]));
    }

    private function uriMatches(string $uri, string $path): bool
    {
        if (rtrim($uri, '/') === rtrim($path, '/')) {
            return true;
        }

        $parts = preg_split('/(\{[^}]+\})/', $uri, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '#^';

        foreach ($parts as $part) {
            $pattern .= preg_match('/^\{[^}]+\}$/', $part) ? '[^/]+' : preg_quote($part, '#');
        }

        $pattern .= '$#';

        return (bool) preg_match($pattern, rtrim($path, '/'));
    }
}
