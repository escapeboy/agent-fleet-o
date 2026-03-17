<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Services\TermsAcceptanceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

class EnsureTermsAccepted
{
    /**
     * Routes that are always accessible, even when terms haven't been accepted.
     * Includes the accept page itself, auth routes, and legal pages.
     */
    private const ALLOWED_ROUTES = [
        'terms.accept',
        'logout',
        'home',
        'legal.terms',
        'legal.privacy',
        'legal.cookies',
        'auth.social.collect-email',
        'auth.social.store-email',
    ];

    public function __construct(
        private readonly TermsAcceptanceService $terms,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only applies to authenticated users
        if (! $request->user()) {
            return $next($request);
        }

        // Skip Livewire wire requests and API routes — only gate full page loads
        if ($request->is('*livewire*') || $request->is('api/*')) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName && in_array($routeName, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($this->terms->requiresAcceptance($request->user())) {
            return Redirect::guest(route('terms.accept'));
        }

        return $next($request);
    }
}
