<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LlmsTxtController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Markdown content negotiation for agents.
 *
 * When a client explicitly prefers `text/markdown`, serve a markdown
 * representation of the page instead of HTML. Browsers are unaffected — they
 * send `text/html` and never `text/markdown`, so they never trigger this.
 *
 * Only routes with a genuinely curated markdown representation are negotiated;
 * everything else falls through to HTML. The homepage maps to `/llms.txt`, the
 * agent-oriented index we already maintain — a mechanical HTML-to-markdown of a
 * marketing page would be strictly worse than that curated document.
 *
 * @see https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/
 */
class NegotiateMarkdown
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->prefersMarkdown($request)) {
            return $next($request);
        }

        $markdown = $this->markdownFor($request);

        if ($markdown === null) {
            return $next($request);
        }

        return new HttpResponse($markdown['body'], 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Location' => $markdown['location'],
            'Vary' => 'Accept',
            'Cache-Control' => 'public, max-age=3600',
            'X-Markdown-Tokens' => (string) (int) ceil(mb_strlen($markdown['body']) / 4),
        ]);
    }

    private function prefersMarkdown(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        $accept = strtolower($request->headers->get('Accept', ''));

        // Agents asking for markdown send `text/markdown`; browsers send
        // `text/html,...` and never name markdown. Requiring markdown present
        // and html absent matches the former without ever catching the latter.
        return str_contains($accept, 'text/markdown') && ! str_contains($accept, 'text/html');
    }

    /**
     * @return array{body: string, location: string}|null
     */
    private function markdownFor(Request $request): ?array
    {
        $path = trim($request->path(), '/');

        if ($path === '' || $path === '/') {
            return [
                'body' => app(LlmsTxtController::class)->compact()->getContent(),
                'location' => url('/llms.txt'),
            ];
        }

        return null;
    }
}
