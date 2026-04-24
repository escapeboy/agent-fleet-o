<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Telemetry\TenantTracerProviderFactory;
use App\Infrastructure\Telemetry\TracerProvider;
use Closure;
use Illuminate\Http\Request;

/**
 * Swaps the bound `TracerProvider` for the current team's per-tenant provider
 * before the request hits controllers/middleware that open spans. Falls back
 * to the platform default when:
 *   - no authenticated user
 *   - user has no current team
 *   - team hasn't configured observability
 *
 * Runs AFTER `SetCurrentTeam` (web) or `ScopeTokenToTeam` (API) so the current
 * team is resolved — register it in the stack accordingly in bootstrap/app.php.
 */
class ApplyTenantTracer
{
    public function __construct(
        private readonly TenantTracerProviderFactory $factory,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $teamId = $request->user()?->current_team_id;
        if ($teamId) {
            $provider = $this->factory->forTeam($teamId);
            app()->instance(TracerProvider::class, $provider);
        }

        return $next($request);
    }
}
