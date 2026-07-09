<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks public, user/agent-generated Website Builder responses as non-indexable.
 *
 * Builder sites are published by agents and end users; served on the apex
 * domain they read as arbitrary third-party content to search-engine crawlers
 * (and once triggered a Google Safe Browsing "deceptive page" listing). This
 * middleware sends `X-Robots-Tag: noindex, nofollow` on every public builder
 * response so no builder page is ever crawled or indexed under fleetq.net.
 */
class SetNoIndexHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
