<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Injects the PostgreSQL RLS context for queue jobs.
 *
 * Horizon workers reuse database connections across jobs, so we MUST use
 * SET LOCAL (transaction-scoped) to prevent the team context leaking from
 * one job to the next.  SET LOCAL only takes effect inside a transaction
 * and automatically resets to the session value at COMMIT or ROLLBACK.
 *
 * The job body is wrapped in a transaction solely to scope the SET LOCAL;
 * the job itself may open nested transactions normally.
 *
 * Requirements:
 * - The job must have a public $teamId property (set by the dispatching code).
 * - The migration must have run to create the agent_fleet_rls role and
 *   the current_team_id() function.
 */
class EnforceTenantContext
{
    private static ?bool $rlsAvailable = null;

    public function handle(object $job, Closure $next): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isRlsAvailable()) {
            $next($job);

            return;
        }

        $teamId = $this->resolveTeamId($job);

        if ($teamId === null) {
            Log::warning('EnforceTenantContext: no team_id on job, running without RLS context', [
                'job' => class_basename($job),
            ]);
            $next($job);

            return;
        }

        // Wrap in a transaction so SET LOCAL and SET LOCAL ROLE are scoped to this job only.
        // Both settings reset automatically at the transaction boundary.
        DB::transaction(function () use ($job, $teamId, $next): void {
            // SET LOCAL: resets to the session value when this transaction commits.
            DB::statement("SELECT set_config('app.current_team_id', ?, true)", [$teamId]);

            // Switch to the non-superuser role inside the transaction.
            // RESET ROLE at commit brings back the original session role.
            DB::statement('SET LOCAL ROLE agent_fleet_rls');

            $next($job);
        });
    }

    private function isRlsAvailable(): bool
    {
        if (static::$rlsAvailable === null) {
            static::$rlsAvailable = (bool) DB::selectOne(
                "SELECT 1 FROM pg_roles WHERE rolname = 'agent_fleet_rls'"
            );
        }

        return static::$rlsAvailable;
    }

    private function resolveTeamId(object $job): ?string
    {
        // Standard BelongsToTeam jobs expose $teamId
        if (property_exists($job, 'teamId') && $job->teamId !== null) {
            return (string) $job->teamId;
        }

        // Some jobs expose it via $team_id
        if (property_exists($job, 'team_id') && $job->team_id !== null) {
            return (string) $job->team_id;
        }

        return null;
    }
}
