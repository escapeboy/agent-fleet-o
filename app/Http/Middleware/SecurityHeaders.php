<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->sendsTransportHeaders()) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

            if (! $response->headers->has('X-Frame-Options')) {
                $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            }

            if (app()->isProduction()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            }
        }

        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // A controller that serves untrusted content (artifact / website previews) sets its own, stricter
        // policy. Never replace it with the app-wide one, which allows inline scripts.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request));
        }

        return $response;
    }

    /**
     * Whether this middleware emits HSTS / X-Frame-Options / nosniff / Referrer-Policy.
     * Deployments whose reverse proxy already sends them can return false to avoid duplicates.
     */
    protected function sendsTransportHeaders(): bool
    {
        return true;
    }

    /**
     * CSP directives keyed by name, so subclasses can adjust single directives.
     *
     * @return array<string, string>
     */
    protected function cspDirectives(Request $request): array
    {
        // Vite HMR dev server origin — only when running locally.
        // Must match VITE_DEV_SERVER_URL in vite.config.js (default http://localhost:5174,
        // which matches the docker-compose host mapping "5174:5173"). Users running Vite
        // on a non-default port should adapt this middleware.
        $viteOrigin = app()->isLocal() ? 'http://localhost:5174' : '';

        // Local dev WebSocket origins: Vite HMR + Reverb via nginx (REVERB_PORT, default 8080).
        // `:*` wildcards the port so any local dev port works.
        // Production uses WSS through the same origin (handled by 'self' + wss:).
        $wsOrigins = app()->isLocal() ? 'ws://localhost:* ws://127.0.0.1:*' : '';

        // Google Analytics loads only after the visitor accepts (see components/analytics/head.blade.php),
        // but the policy has to let it through then. Added only where a GA id is configured.
        $ga = config('services.google_analytics.id') ? ' https://www.googletagmanager.com' : '';
        $gaBeacon = config('services.google_analytics.id') ? ' https://*.google-analytics.com https://*.analytics.google.com' : '';

        $directives = [
            'default-src' => "'self'",
            // 'unsafe-inline' required for Blade-injected Alpine.js bootstrapping.
            // 'unsafe-eval' required for Alpine.js v3 / Livewire 4 expression evaluation.
            'script-src' => "'self' 'unsafe-inline' 'unsafe-eval' {$viteOrigin} https://cdn.jsdelivr.net https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://plausible.io https://unpkg.com{$ga}",
            // Inline styles are required for Tailwind JIT utilities applied via Alpine.
            'style-src' => "'self' 'unsafe-inline' {$viteOrigin} https://fonts.bunny.net https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net",
            // Restrict images to known origins; drop the open https: wildcard.
            'img-src' => "'self' data: blob: https://avatars.githubusercontent.com https://secure.gravatar.com{$ga}{$gaBeacon}",
            // Fonts: self + Vite HMR (Font Awesome webfonts in dev) + data: (base64 inline)
            // + Bunny CDN (Inter font CSS + woff2) + Cloudflare CDN (Font Awesome bundled by GrapesJS).
            'font-src' => "'self' data: {$viteOrigin} https://fonts.bunny.net https://cdnjs.cloudflare.com",
            // XHR/fetch/WS: self + Vite HMR (HTTP + WS) + Reverb (dev WS) + Stripe + Plausible + unpkg.
            'connect-src' => "'self' {$viteOrigin} {$wsOrigins} wss: https://api.stripe.com https://plausible.io https://unpkg.com{$ga}{$gaBeacon}",
            // Stripe payment iframes
            'frame-src' => "'self' https://js.stripe.com https://hooks.stripe.com",
            // CRITICAL for PWA: allow service workers from same origin
            'worker-src' => "'self'",
            // Web App Manifest
            'manifest-src' => "'self'",
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
        ];

        // The OAuth consent form posts to a same-origin endpoint that answers with a redirect to the client's
        // redirect_uri (claude.ai, ChatGPT, ...). Browsers apply form-action to that redirect, so on the
        // authorization page the directive has to be left out or the approve button does nothing.
        if ($request->routeIs('passport.authorizations.authorize')) {
            unset($directives['form-action']);
        }

        return $directives;
    }

    protected function contentSecurityPolicy(Request $request): string
    {
        return collect($this->cspDirectives($request))
            ->map(fn (string $value, string $name): string => trim($name.' '.$value))
            ->implode('; ');
    }
}
