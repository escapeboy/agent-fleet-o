<?php

namespace App\Jobs\Middleware;

use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use Closure;
use Illuminate\Support\Facades\Log;

class EnforceExecutionTtl
{
    public function handle(object $job, Closure $next): void
    {
        if (! property_exists($job, 'experimentId')) {
            $next($job);

            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($job->experimentId);

        if (! $experiment) {
            $next($job);

            return;
        }

        /** @var array|null $constraints */
        $constraints = $experiment->constraints;
        $maxTtlMinutes = $constraints['max_ttl_minutes']
            ?? config('experiments.default_ttl_minutes', 120);

        // Find the earliest running stage start time, or fall back to experiment updated_at
        $startedAt = $experiment->stages()
            ->whereIn('status', ['running', 'completed'])
            ->min('started_at') ?? $experiment->updated_at;

        if ($startedAt && now()->diffInMinutes($startedAt) > $maxTtlMinutes) {
            Log::warning('EnforceExecutionTtl: TTL exceeded, killing experiment', [
                'experiment_id' => $experiment->id,
                'ttl_minutes' => $maxTtlMinutes,
                'elapsed_minutes' => now()->diffInMinutes($startedAt),
            ]);

            app(KillExperimentAction::class)->execute(
                $experiment,
                "TTL exceeded: {$maxTtlMinutes} minutes",
            );

            return;
        }

        $next($job);
    }
}
