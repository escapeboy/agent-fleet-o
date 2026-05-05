<?php

namespace App\Http\Middleware;

use App\Domain\Website\Models\Website;
use App\Http\Controllers\PublicSiteController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intercepts requests for custom domains and serves the matching published website.
 *
 * When a request arrives at a domain that is not the app's own domain,
 * this middleware looks up a published Website with a matching custom_domain
 * and proxies the request to PublicSiteController::page().
 *
 * Custom domain entries are cached for 60 seconds to avoid per-request DB lookups.
 */
class ResolveWebsiteByDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $appHost = parse_url(config('app.url'), PHP_URL_HOST) ?? '';

        // Skip: this is the app's own domain or a subdomain of it
        if ($host === $appHost || str_ends_with($host, '.'.$appHost)) {
            return $next($request);
        }

        $websiteId = Cache::remember("custom_domain:{$host}", 60, function () use ($host): ?string {
            return Website::withoutGlobalScopes()
                ->where('custom_domain', $host)
                ->where('status', 'published')
                ->value('id');
        });

        if (! $websiteId) {
            return $next($request);
        }

        $website = Website::withoutGlobalScopes()->find($websiteId);

        if (! $website) {
            return $next($request);
        }

        // Derive page slug from the request path (empty path → homepage)
        $pageSlug = trim($request->path(), '/') ?: 'index';

        return app(PublicSiteController::class)->page($request, $website->slug, $pageSlug);
    }
}
