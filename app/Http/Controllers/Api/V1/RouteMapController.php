<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signal\Actions\RegisterRouteMapAction;
use App\Domain\Signal\Models\RouteMap;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Bug Reports
 */
class RouteMapController extends Controller
{
    /**
     * Register or update the route map for a project.
     *
     * @response 201 {"id": "uuid", "project": "string", "release": "string", "routes_count": 42}
     */
    public function store(Request $request, RegisterRouteMapAction $action): JsonResponse
    {
        $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'release' => ['nullable', 'string', 'max:100'],
            'routes' => ['required', 'array', 'max:2000'],
            'routes.*.method' => ['required', 'string'],
            'routes.*.uri' => ['required', 'string'],
            'routes.*.name' => ['nullable', 'string'],
            'routes.*.controller' => ['nullable', 'string'],
            'routes.*.livewire_component' => ['nullable', 'string'],
        ]);

        $teamId = $request->user()->current_team_id;

        $routeMap = $action->execute(
            teamId: $teamId,
            project: $request->input('project'),
            release: $request->input('release', ''),
            routes: $request->input('routes'),
        );

        return response()->json([
            'id' => $routeMap->id,
            'project' => $routeMap->project,
            'release' => $routeMap->release,
            'routes_count' => count($routeMap->routes ?? []),
        ], 201);
    }

    /**
     * Look up route information for a given project URL.
     *
     * @response 200 {"project": "string", "url": "string", "route": {...}}
     * @response 404 {"error": "no_route_match"}
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $teamId = $request->user()->current_team_id;
        $project = $request->input('project');
        $url = $request->input('url');

        $routeMap = RouteMap::where('team_id', $teamId)
            ->where('project', $project)
            ->first();

        if (! $routeMap) {
            return response()->json(['error' => 'no_route_match'], 404);
        }

        $path = ltrim(parse_url($url, PHP_URL_PATH) ?? $url, '/');

        foreach ($routeMap->routes ?? [] as $route) {
            $uri = $route['uri'] ?? '';

            if ($this->uriMatches($uri, $path)) {
                return response()->json([
                    'project' => $project,
                    'url' => $url,
                    'route' => $route,
                ]);
            }
        }

        return response()->json(['error' => 'no_route_match'], 404);
    }

    private function uriMatches(string $uri, string $path): bool
    {
        $uri = trim($uri, '/');
        $path = trim($path, '/');

        if ($uri === $path) {
            return true;
        }

        $parts = preg_split('/(\{[^}]+\})/', $uri, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '#^';

        foreach ($parts as $part) {
            $pattern .= preg_match('/^\{[^}]+\}$/', $part) ? '[^/]+' : preg_quote($part, '#');
        }

        $pattern .= '$#';

        return (bool) preg_match($pattern, $path);
    }
}
