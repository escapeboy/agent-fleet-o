<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Telemetry\Sentry\SentryContext;
use Closure;
use Illuminate\Http\Request;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Pushes per-request user/team context onto the Sentry scope.
 *
 * Wired in `bootstrap/app.php` after `SetCurrentTeam` so `auth()->user()` and
 * `current_team_id` are populated. Unlike the queue middleware, the web
 * request's scope is naturally bounded by Laravel's request lifecycle —
 * we don't need `Hub::withScope()` because the worker reset happens between
 * requests in production (FPM resets globals).
 *
 * For Octane / long-running PHP workers, the SentryHandler binding in
 * sentry-laravel takes care of resetting scope between requests, so this
 * middleware remains safe.
 */
final class SentryContextWebMiddleware
{
    public function __construct(
        private readonly SentryContext $context,
        private readonly HubInterface $hub,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->hub->configureScope(function ($scope) use ($request): void {
                $user = $request->user();
                $context = [
                    'team_id' => $user?->current_team_id,
                    'user_id' => $user?->id,
                ];

                $this->context->apply($scope, $context);
            });
        } catch (Throwable) {
            // Never fail a web request because of observability — silently skip.
        }

        return $next($request);
    }
}
