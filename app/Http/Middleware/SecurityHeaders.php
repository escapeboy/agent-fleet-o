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

        // Vite HMR dev server origin — only when running locally.
        // Must match VITE_DEV_SERVER_URL in vite.config.js (default http://localhost:5174,
        // which matches the docker-compose host mapping "5174:5173"). Users running Vite
        // on a non-default port should adapt this middleware.
        $viteOrigin = app()->isLocal() ? 'http://localhost:5174' : '';

        // Local dev WebSocket origins: Vite HMR + Reverb via nginx (REVERB_PORT, default 8080).
        // `:*` wildcards the port so any local dev port works.
        // Production uses WSS through the same origin (handled by 'self' + wss:).
        $wsOrigins = app()->isLocal() ? 'ws://localhost:* ws://127.0.0.1:*' : '';

        $csp = implode('; ', [
            "default-src 'self'",
            // 'unsafe-inline' required for Blade-injected Alpine.js bootstrapping.
            // 'unsafe-eval' required for Alpine.js v3 / Livewire 4 expression evaluation.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' {$viteOrigin} https://cdn.jsdelivr.net https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://plausible.io https://unpkg.com",
            // Inline styles are required for Tailwind JIT utilities applied via Alpine.
            "style-src 'self' 'unsafe-inline' {$viteOrigin} https://fonts.bunny.net https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net",
            // Restrict images to known origins; drop the open https: wildcard.
            "img-src 'self' data: blob: https://avatars.githubusercontent.com https://secure.gravatar.com",
            // Fonts: self + Vite HMR (Font Awesome webfonts in dev) + data: (base64 inline)
            // + Bunny CDN (Inter font CSS + woff2) + Cloudflare CDN (Font Awesome bundled by GrapesJS).
            "font-src 'self' data: {$viteOrigin} https://fonts.bunny.net https://cdnjs.cloudflare.com",
            // XHR/fetch/WS: self + Vite HMR (HTTP + WS) + Reverb (dev WS) + Stripe + Plausible + unpkg.
            "connect-src 'self' {$viteOrigin} {$wsOrigins} wss: https://api.stripe.com https://plausible.io https://unpkg.com",
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
