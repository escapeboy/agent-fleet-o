<?php

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Models\RouteMap;

class SuspectFilesAnalyzer
{
    /**
     * Build a ranked list of suspect files from all available evidence.
     *
     * @param  array<string, mixed>  $payload  Signal payload
     * @return array{suspect_files: array, source_hints: array}
     */
    public function analyze(array $payload, string $teamId, ?string $projectKey): array
    {
        $candidates = [];
        $sourceHints = [];

        // 1. Resolved stack trace frames (highest confidence)
        foreach ($payload['resolved_errors'] ?? [] as $error) {
            $frames = $error['resolved_frames'] ?? [];
            $firstProject = null;

            foreach ($frames as $frame) {
                if (! ($frame['isProjectCode'] ?? false)) {
                    continue;
                }

                if ($firstProject === null) {
                    $firstProject = $frame;
                    $this->addCandidate($candidates, $frame['file'], 0.95, 'First project frame in stack trace');
                } else {
                    $this->addCandidate($candidates, $frame['file'], 0.7, 'Project frame in stack trace');
                }
            }
        }

        // 2. Route map (look up the page URL)
        $url = $payload['url'] ?? null;
        $routeName = $payload['route_name'] ?? null;
        if ($url && $projectKey) {
            $routeEntry = $this->lookupRoute($teamId, $projectKey, $url, $routeName);

            if ($routeEntry) {
                $sourceHints['route'] = $routeEntry;

                if (! empty($routeEntry['controller'])) {
                    $controllerFile = $this->controllerToPath($routeEntry['controller']);
                    $safeUrl = preg_replace('/[\x00-\x1F\x7F]/u', '', mb_substr($url, 0, 150));
                    $this->addCandidate($candidates, $controllerFile, 0.85, 'Route controller for '.$safeUrl);

                    // Blade view from controller convention
                    $viewFile = $this->controllerToView($routeEntry['controller']);
                    if ($viewFile) {
                        $this->addCandidate($candidates, $viewFile, 0.6, 'View for route controller');
                    }
                }

                if (! empty($routeEntry['livewire_component'])) {
                    $livewirePath = $this->livewireToPath($routeEntry['livewire_component']);
                    $this->addCandidate($candidates, $livewirePath, 0.85, 'Active Livewire component on error page');
                }
            }
        }

        // 3. Failed network requests matched to route map
        $failedResponses = $payload['failed_responses'] ?? [];
        if (is_string($failedResponses)) {
            $failedResponses = json_decode($failedResponses, true) ?? [];
        }

        foreach ($failedResponses as $failed) {
            $failedUrl = $failed['url'] ?? '';
            if (! $failedUrl || ! $projectKey) {
                continue;
            }

            $routeEntry = $this->lookupRoute($teamId, $projectKey, $failedUrl);
            if ($routeEntry && ! empty($routeEntry['controller'])) {
                $controllerFile = $this->controllerToPath($routeEntry['controller']);
                $safeFailedUrl = preg_replace('/[\x00-\x1F\x7F]/u', '', mb_substr($failedUrl, 0, 150));
                $this->addCandidate($candidates, $controllerFile, 0.8, 'Controller for failed API request: '.$safeFailedUrl);
            }
        }

        // 4. Livewire components from widget data
        $livewireComponents = $payload['livewire_components'] ?? [];
        if (is_string($livewireComponents)) {
            $livewireComponents = json_decode($livewireComponents, true) ?? [];
        }

        foreach ($livewireComponents as $component) {
            $class = $component['class'] ?? '';
            if (! $class) {
                continue;
            }

            $path = $this->livewireToPath($class);
            $this->addCandidate($candidates, $path, 0.9, 'Active Livewire component on error page');
        }

        // Deduplicate + sort by confidence descending
        $merged = $this->merge($candidates);

        usort($merged, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return [
            'suspect_files' => array_values($merged),
            'source_hints' => $sourceHints,
        ];
    }

    private function addCandidate(array &$candidates, string $path, float $confidence, string $reason): void
    {
        if (empty($path)) {
            return;
        }

        $candidates[] = ['path' => $path, 'confidence' => $confidence, 'reason' => $reason];
    }

    /**
     * Deduplicate by path, keeping highest confidence entry.
     *
     * @param  array<int, array>  $candidates
     * @return array<int, array>
     */
    private function merge(array $candidates): array
    {
        $byPath = [];

        foreach ($candidates as $candidate) {
            $path = $candidate['path'];

            if (! isset($byPath[$path]) || $candidate['confidence'] > $byPath[$path]['confidence']) {
                $byPath[$path] = $candidate;
            }
        }

        return array_values($byPath);
    }

    private function lookupRoute(string $teamId, string $project, string $url, ?string $routeName = null): ?array
    {
        $routeMap = RouteMap::where('team_id', $teamId)
            ->where('project', $project)
            ->first();

        if (! $routeMap) {
            return null;
        }

        $routes = $routeMap->routes ?? [];

        // Fast path: match by route name from payload (100% accurate when available)
        if ($routeName) {
            foreach ($routes as $route) {
                if (($route['name'] ?? null) === $routeName) {
                    return $route;
                }
            }
        }

        // Fallback: match by URL path
        $path = ltrim(parse_url($url, PHP_URL_PATH) ?? $url, '/');

        foreach ($routes as $route) {
            $uri = $route['uri'] ?? '';

            if ($this->uriMatches($uri, $path)) {
                return $route;
            }
        }

        return null;
    }

    private function uriMatches(string $uri, string $path): bool
    {
        // Normalize both sides: strip leading and trailing slashes
        $uri = trim($uri, '/');
        $path = trim($path, '/');

        // Exact match
        if ($uri === $path) {
            return true;
        }

        // Pattern match: split on {param} tokens, quote each literal segment, rejoin with [^/]+
        // This prevents regex injection from URIs containing metacharacters like . ( ) * + ?
        $parts = preg_split('/(\{[^}]+\})/', $uri, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '#^';

        foreach ($parts as $part) {
            $pattern .= preg_match('/^\{[^}]+\}$/', $part) ? '[^/]+' : preg_quote($part, '#');
        }

        $pattern .= '$#';

        return (bool) preg_match($pattern, $path);
    }

    /**
     * Convert "App\Http\Controllers\SettingsController@profile" to file path.
     */
    private function controllerToPath(string $controller): string
    {
        // Strip @method suffix
        $class = explode('@', $controller)[0];

        // Convert namespace to path: App\Http\Controllers\Foo → app/Http/Controllers/Foo.php
        $path = str_replace('\\', '/', $class);
        $path = preg_replace('/^App\//', 'app/', $path);

        return $path.'.php';
    }

    /**
     * Guess blade view from controller: SettingsController@profile → resources/views/settings/profile.blade.php
     */
    private function controllerToView(string $controller): ?string
    {
        if (! str_contains($controller, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $controller, 2);
        $parts = explode('\\', $class);
        $name = array_pop($parts);

        // Strip "Controller" suffix
        $name = str_replace('Controller', '', $name);
        $prefix = strtolower(preg_replace('/([A-Z])/', '-$1', lcfirst($name)));
        $action = strtolower(preg_replace('/([A-Z])/', '-$1', lcfirst($method)));

        return "resources/views/{$prefix}/{$action}.blade.php";
    }

    /**
     * Convert "App\Livewire\SettingsPage" to file path.
     */
    private function livewireToPath(string $class): string
    {
        $path = str_replace('\\', '/', $class);
        $path = preg_replace('/^App\//', 'app/', $path);

        return $path.'.php';
    }
}
