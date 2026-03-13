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

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $csp = implode('; ', [
            "default-src 'self'",
            // 'unsafe-inline' required for Blade-injected Alpine.js bootstrapping.
            // 'unsafe-eval' required for Alpine.js v3 / Livewire 4 expression evaluation.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://plausible.io",
            // Inline styles are required for Tailwind JIT utilities applied via Alpine.
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            // Restrict images to known origins; drop the open https: wildcard.
            "img-src 'self' data: blob: https://avatars.githubusercontent.com https://secure.gravatar.com",
            // Fonts: self + Bunny CDN
            "font-src 'self' https://fonts.bunny.net",
            // XHR/fetch/WS: self + Stripe API + Plausible analytics
            "connect-src 'self' wss: https://api.stripe.com https://plausible.io",
            // Stripe payment iframes
            "frame-src 'self' https://js.stripe.com https://hooks.stripe.com",
            // CRITICAL for PWA: allow service workers from same origin
            "worker-src 'self'",
            // Web App Manifest
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
