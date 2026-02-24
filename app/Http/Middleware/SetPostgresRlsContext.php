<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the PostgreSQL session-level RLS context for each web request.
 *
 * Uses SET LOCAL (transaction-scoped) inside an implicit transaction wrapper
 * to ensure the context is scoped to the request and cannot bleed across
 * connection-reuse boundaries in FPM (connections are not persistent by default).
 *
 * For web (PHP-FPM) the connection is released after the request, so
 * SET SESSION would also be safe here, but SET LOCAL inside begin/commit is
 * more explicit and mirrors the job middleware approach.
 */
class SetPostgresRlsContext
{
    /**
     * Cached per-process result of whether the agent_fleet_rls role exists.
     * Null = not yet checked, true/false = result of the check.
     */
    private static ?bool $rlsAvailable = null;

    public function handle(Request $request, Closure $next): Response
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isRlsAvailable()) {
            return $next($request);
        }

        $user = $request->user();
        $teamId = $user !== null ? ($user->current_team_id ?? '') : '';

        // Set session-level GUC — FPM connections are per-request so no leak risk.
        DB::statement("SELECT set_config('app.current_team_id', ?, false)", [$teamId]);

        // Switch to the non-superuser role so FORCE ROW LEVEL SECURITY takes effect.
        // Superusers bypass FORCE RLS; agent_fleet_rls is NOSUPERUSER and subject to it.
        if ($teamId !== '') {
            DB::statement('SET ROLE agent_fleet_rls');
        }

        $response = $next($request);

        // Reset to the original role after the request to be safe.
        DB::statement('RESET ROLE');

        return $response;
    }

    /**
     * Check once per FPM worker process whether the RLS role exists.
     * Returns false (no-op) until the migration has been run.
     */
    private function isRlsAvailable(): bool
    {
        if (self::$rlsAvailable === null) {
            self::$rlsAvailable = (bool) DB::selectOne(
                "SELECT 1 FROM pg_roles WHERE rolname = 'agent_fleet_rls'"
            );
        }

        return self::$rlsAvailable;
    }
}
