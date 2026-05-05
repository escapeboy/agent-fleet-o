<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Infrastructure\Telemetry\TenantTracerProviderFactory;
use App\Infrastructure\Telemetry\TracerProvider;
use Closure;

/**
 * Queue-job counterpart of `App\Http\Middleware\ApplyTenantTracer`.
 *
 * Horizon workers process jobs from many teams back-to-back in the same
 * process, so the bound `TracerProvider` must be swapped per-job to route
 * spans through the correct team's OTLP exporter. Without this, Sprint 9's
 * per-team observability config only catches HTTP-spawned spans — LLM /
 * agent / experiment / migration spans generated inside queue workers all
 * land on the platform default.
 *
 * Team resolution uses three strategies, in order:
 *   1. Public readonly `$teamId` property (existing FleetQ convention —
 *      BaseStageJob, ExecuteCrewJob, ProcessAssistantMessageJob, most
 *      new jobs already carry this).
 *   2. `teamId()` method returning a string.
 *   3. No team context → leave platform default in place.
 */
class ApplyTenantTracer
{
    public function handle(object $job, Closure $next): mixed
    {
        $teamId = $this->resolveTeamId($job);

        if ($teamId !== null && $teamId !== '') {
            $factory = app(TenantTracerProviderFactory::class);
            $provider = $factory->forTeam($teamId);
            app()->instance(TracerProvider::class, $provider);
        }

        return $next($job);
    }

    private function resolveTeamId(object $job): ?string
    {
        if (property_exists($job, 'teamId')) {
            $value = $job->teamId ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (method_exists($job, 'teamId')) {
            $value = $job->teamId();
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
