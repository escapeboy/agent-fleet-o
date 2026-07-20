<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Advertises the machine-readable discovery surface via RFC 8288 Link headers
 * so agents can find the API catalog and docs without parsing HTML.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8288
 * @see https://www.rfc-editor.org/rfc/rfc9727#section-3
 */
class AgentDiscoveryLinks
{
    private const LINKS = [
        '</.well-known/api-catalog>; rel="api-catalog"; type="application/linkset+json"',
        '</docs/api.json>; rel="service-desc"; type="application/vnd.oai.openapi+json"',
        '</docs/api>; rel="service-doc"; type="text/html"',
        '</llms.txt>; rel="describedby"; type="text/markdown"',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable()) {
            return $response;
        }

        if (! $this->isHtml($response)) {
            return $response;
        }

        foreach (self::LINKS as $link) {
            $response->headers->set('Link', $link, false);
        }

        return $response;
    }

    private function isHtml(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return is_string($contentType) && str_contains($contentType, 'text/html');
    }
}
