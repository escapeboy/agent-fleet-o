<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Serves /sitemap.xml per the sitemaps.org protocol.
 *
 * The URL list is derived from the router rather than hand-maintained, so
 * pages added in either the base or the cloud edition appear automatically.
 * Only parameterless public GET routes are emitted; anything behind auth or
 * matching a denied prefix is excluded.
 */
class SitemapController extends Controller
{
    /**
     * First path segments that are machine surfaces, assets, auth flows, or
     * tenant-scoped. Matched exactly or as a `segment-*` prefix, which is what
     * catches Livewire's hashed asset route (`livewire-<hash>/...`).
     */
    private const DENY_SEGMENTS = [
        'api', 'mcp', 'oauth', '.well-known', 'livewire', 'storage', 'build',
        'horizon', 'telescope', 'pulse', 'metrics', 'broadcasting', 'sanctum',
        'admin', 'share', 'files', 'shop', 'sso', 'auth', 'up', 'get',
        'webauthn', 'account', 'authorize', 'continue', 'end-session',
    ];

    /** Paths that exist publicly but must not be indexed. */
    private const DENY_PATTERNS = [
        'login', 'register', 'logout', 'password', 'forgot', 'reset',
        'two-factor', 'setup', 'onboarding', 'invitations', 'trial',
        'confirm', 'verify', 'accept',
    ];

    public function __invoke(): Response
    {
        $urls = collect($this->routeUrls())
            ->merge($this->docsUrls())
            ->unique()
            ->sort()
            ->values();

        $body = $this->renderXml($urls->all());

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @return list<string>
     */
    private function routeUrls(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => $this->isPublicPage($route))
            ->map(fn (RoutingRoute $route) => url('/'.ltrim($route->uri(), '/')))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function docsUrls(): array
    {
        return collect(DocsController::PAGES)
            ->filter(fn (string $slug) => view()->exists('docs.'.$slug))
            ->map(fn (string $slug) => url('/docs/'.$slug))
            ->values()
            ->all();
    }

    private function isPublicPage(RoutingRoute $route): bool
    {
        if (! in_array('GET', $route->methods(), true)) {
            return false;
        }

        $uri = trim($route->uri(), '/');

        // Parameterised routes need data we cannot enumerate safely here.
        if (str_contains($uri, '{')) {
            return false;
        }

        if ($uri === '' || $uri === '/') {
            return true;
        }

        // A dot in the final segment means a machine document or asset
        // (api.json, llms.txt, sitemap.xml, livewire.js) — never a page.
        if (str_contains(basename($uri), '.')) {
            return false;
        }

        $firstSegment = explode('/', $uri)[0];

        foreach (self::DENY_SEGMENTS as $segment) {
            if ($firstSegment === $segment || Str::startsWith($firstSegment, $segment.'-')) {
                return false;
            }
        }

        foreach (self::DENY_PATTERNS as $pattern) {
            if (str_contains($uri, $pattern)) {
                return false;
            }
        }

        return ! $this->requiresAuth($route);
    }

    private function requiresAuth(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth' || Str::startsWith($middleware, ['auth:', 'auth.'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $urls
     */
    private function renderXml(array $urls): string
    {
        $entries = implode('', array_map(
            fn (string $url) => '<url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>',
            $urls,
        ));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$entries
            .'</urlset>';
    }
}
