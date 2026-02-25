<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Jobs\TimeGateDelayJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class HandleTimeGateAction
{
    /**
     * Activate a time gate step: mark it waiting, schedule a wakeup job.
     *
     * The node config should contain:
     *   - delay_seconds (int): how long to wait before continuing
     *   - delay_until (ISO8601 string, optional): absolute timestamp to wait until
     */
    public function execute(PlaybookStep $step, Experiment $experiment, array $nodeData): void
    {
        $config = is_string($nodeData['config'] ?? null)
            ? json_decode($nodeData['config'], true)
            : ($nodeData['config'] ?? []);

        $resumeAt = $this->resolveResumeAt($config);

        $step->update([
            'status' => 'waiting_time',
            'started_at' => now(),
            'resume_at' => $resumeAt,
        ]);

        $delaySeconds = max(0, now()->diffInSeconds($resumeAt, false));

        Log::info('HandleTimeGateAction: time gate activated', [
            'step_id' => $step->id,
            'experiment_id' => $experiment->id,
            'resume_at' => $resumeAt->toIso8601String(),
            'delay_seconds' => $delaySeconds,
        ]);

        // Dispatch a delayed job as the primary wakeup mechanism.
        // PollWorkflowTimeGatesCommand provides a durable fallback in case the job is lost.
        TimeGateDelayJob::dispatch(
            $step->id,
            $experiment->id,
            $step->workflow_node_id,
        )->delay($resumeAt);
    }

    private function resolveResumeAt(array $config): Carbon
    {
        // Absolute timestamp takes priority
        if (! empty($config['delay_until'])) {
            try {
                $at = Carbon::parse($config['delay_until']);
                if ($at->isFuture()) {
                    return $at;
                }
            } catch (\Throwable) {
                // Fall through to delay_seconds
            }
        }

        $seconds = max(1, (int) ($config['delay_seconds'] ?? 60));

        return now()->addSeconds($seconds);
    }
}
