<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds `mcp.team_id` in the container from the authenticated user's current team.
 *
 * Required for HTTP MCP transports (`/mcp`, `/mcp/full`) on the base community
 * edition: tool handlers expect `app('mcp.team_id')` to resolve, but no other
 * middleware on the base routes populates the binding. Without this, the
 * Compact umbrella's wrapped tools (`signal_manage`, `memory_manage`) throw
 * `BindingResolutionException` because `app('mcp.team_id')` falls through to
 * `Container::build()` and can't autoresolve the dotted string.
 *
 * Cloud's `Cloud\Http\Middleware\McpTeamContext` is a strict superset of this
 * (additional Postgres RLS context, scope checks). On cloud routes that
 * middleware overwrites the binding via `instance()` and wins by registration
 * order — so this middleware can stay registered without conflicting.
 */
class McpTeamBinding
{
    public function handle(Request $request, Closure $next): Response
    {
        $teamId = $request->user()?->current_team_id;

        if ($teamId) {
            // instance() is the correct shape here: a non-null value is fully
            // visible to bound() and app() resolution (no null-blindness).
            // Defensive callers using `bound() ? app() : fallback` see this as
            // "bound", and bare callers using `app() ?? fallback` get the value
            // straight back.
            $this->app('mcp.team_id', $teamId);
        }

        return $next($request);
    }

    /**
     * Wrapper to keep the middleware testable without booting the full app —
     * tests can swap this method to capture the binding without an actual
     * container roundtrip.
     */
    protected function app(string $abstract, mixed $value): void
    {
        app()->instance($abstract, $value);
    }
}
