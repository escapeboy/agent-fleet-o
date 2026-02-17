<?php

namespace App\Jobs\Middleware;

use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use Closure;
use Illuminate\Support\Facades\Log;

class EnforceExecutionDepth
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
        $maxDepth = $constraints['max_execution_depth']
            ?? config('experiments.default_max_depth', 50);

        $currentDepth = $experiment->stages()
            ->where('status', 'completed')
            ->count();

        if ($currentDepth >= $maxDepth) {
            Log::warning('EnforceExecutionDepth: Depth limit reached, killing experiment', [
                'experiment_id' => $experiment->id,
                'max_depth' => $maxDepth,
                'current_depth' => $currentDepth,
            ]);

            app(KillExperimentAction::class)->execute(
                $experiment,
                "Execution depth limit reached: {$maxDepth} stages",
            );

            return;
        }

        $next($job);
    }
}
