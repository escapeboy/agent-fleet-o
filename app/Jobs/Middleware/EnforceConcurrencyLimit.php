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

        if (! $experiment || ! $experiment->agent_id) {
            $next($job);

            return;
        }

        $maxConcurrent = $experiment->constraints['max_concurrent_executions']
            ?? config('experiments.default_max_concurrent', 5);

        $running = Experiment::withoutGlobalScopes()
            ->where('agent_id', $experiment->agent_id)
            ->where('id', '!=', $experiment->id)
            ->whereIn('status', [
                ExperimentStatus::Running,
                ExperimentStatus::AiProcessing,
                ExperimentStatus::ToolExecution,
            ])
            ->count();

        if ($running >= $maxConcurrent) {
            Log::info('EnforceConcurrencyLimit: Agent at concurrency limit, delaying job', [
                'experiment_id' => $experiment->id,
                'agent_id' => $experiment->agent_id,
                'max_concurrent' => $maxConcurrent,
                'current_running' => $running,
            ]);

            $job->release(30); // Retry in 30 seconds

            return;
        }

        $next($job);
    }
}
