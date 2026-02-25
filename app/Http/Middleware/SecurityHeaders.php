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

        // Content Security Policy — shipped in Report-Only mode first.
        // Monitor violations, then switch to Content-Security-Policy once clean.
        $csp = implode('; ', [
            "default-src 'self'",
            // Allow inline scripts (Blade inline scripts, Alpine.js) and CDN Alpine on public pages
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://plausible.io",
            // Allow inline styles (Tailwind utilities) and Bunny fonts
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            // Images: self, data URIs, blobs (chart.js canvas), and any HTTPS (avatars, OG images)
            "img-src 'self' data: blob: https:",
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

        $response->headers->set('Content-Security-Policy-Report-Only', $csp);

        return $response;
    }
}
