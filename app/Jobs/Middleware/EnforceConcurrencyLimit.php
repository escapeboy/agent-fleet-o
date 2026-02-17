<?php

namespace App\Jobs\Middleware;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Closure;
use Illuminate\Support\Facades\Log;

class EnforceConcurrencyLimit
{
    public function handle(object $job, Closure $next): void
    {
        if (! property_exists($job, 'experimentId')) {
            $next($job);

            return;
        }

        $experiment = Experiment::withoutGlobalScopes()->find($job->experimentId);

        if (! $experiment || ! $experiment->user_id) {
            $next($job);

            return;
        }

        /** @var array|null $constraints */
        $constraints = $experiment->constraints;
        $maxConcurrent = $constraints['max_concurrent_executions']
            ?? config('experiments.default_max_concurrent', 5);

        $running = Experiment::withoutGlobalScopes()
            ->where('user_id', $experiment->user_id)
            ->where('id', '!=', $experiment->id)
            ->whereIn('status', [
                ExperimentStatus::Executing,
                ExperimentStatus::Building,
                ExperimentStatus::Scoring,
            ])
            ->count();

        if ($running >= $maxConcurrent) {
            Log::info('EnforceConcurrencyLimit: User at concurrency limit, delaying job', [
                'experiment_id' => $experiment->id,
                'user_id' => $experiment->user_id,
                'max_concurrent' => $maxConcurrent,
                'current_running' => $running,
            ]);

            $job->release(30); // Retry in 30 seconds

            return;
        }

        $next($job);
    }
}
