<?php

namespace App\Jobs\Middleware;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Closure;
use Illuminate\Support\Facades\Log;

class CheckKillSwitch
{
    public function handle(object $job, Closure $next): void
    {
        if (!property_exists($job, 'experimentId')) {
            $next($job);
            return;
        }

        $experiment = Experiment::find($job->experimentId);

        if (!$experiment) {
            Log::warning('CheckKillSwitch: Experiment not found', [
                'experiment_id' => $job->experimentId,
            ]);
            return;
        }

        if ($experiment->status === ExperimentStatus::Killed) {
            Log::info('CheckKillSwitch: Experiment killed, skipping job', [
                'experiment_id' => $experiment->id,
                'job' => class_basename($job),
            ]);
            return;
        }

        if ($experiment->status === ExperimentStatus::Paused) {
            Log::info('CheckKillSwitch: Experiment paused, releasing job back to queue', [
                'experiment_id' => $experiment->id,
                'job' => class_basename($job),
            ]);
            $job->release(60);
            return;
        }

        $next($job);
    }
}
